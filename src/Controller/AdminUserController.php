<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Security\Authorize;
use App\Service\AdminUserService;
use Twig\Environment;

final readonly class AdminUserController
{
    public function __construct(
        private AdminUserService $service,
        private Authorize $authorize,
        private Environment $twig,
    ) {
    }

    public function index(Request $request): void
    {
        $admin = $this->authorize->requireAdmin();

        $page = max(1, (int) $request->get('page', 1));
        $perPage = 25;
        $result = $this->service->paginate($page, $perPage);

        echo $this->twig->render('admin/users.twig', [
            'is_logged_in' => true,
            'user'         => $admin,
            'admin_self_id' => (int) $admin['id'],
            'users'        => $result['rows'],
            'total'        => $result['total'],
            'page'         => $page,
            'per_page'     => $perPage,
            'total_pages'  => max(1, (int) ceil($result['total'] / $perPage)),
            'audit'        => $this->service->recentAudit(50),
            'flash'        => $_SESSION['flash'] ?? null,
            'flash_error'  => $_SESSION['flash_error'] ?? null,
            'csrf_token'   => $_SESSION['csrf_token'] ?? '',
            'api_limit'    => $_SESSION['api_limit'] ?? '?',
        ]);

        unset($_SESSION['flash'], $_SESSION['flash_error']);
    }

    public function toggleAdmin(Request $request): void
    {
        $actor = $this->authorize->requireAdmin();
        $targetId = (int) $request->post('user_id');

        try {
            $this->service->toggleAdmin((int) $actor['id'], $targetId);
            $_SESSION['flash'] = "Admin flag toggled for #{$targetId}.";
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        header('Location: /admin/users');
        exit;
    }

    public function delete(Request $request): void
    {
        $actor = $this->authorize->requireAdmin();
        $targetId = (int) $request->post('user_id');

        try {
            $this->service->softDelete((int) $actor['id'], $targetId);
            $_SESSION['flash'] = "User #{$targetId} soft-deleted.";
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        header('Location: /admin/users');
        exit;
    }

    public function resendVerification(Request $request): void
    {
        $actor = $this->authorize->requireAdmin();
        $targetId = (int) $request->post('user_id');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        try {
            $this->service->resendVerification((int) $actor['id'], $targetId, $ip);
            $_SESSION['flash'] = "Verification email re-sent to #{$targetId}.";
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        header('Location: /admin/users');
        exit;
    }
}

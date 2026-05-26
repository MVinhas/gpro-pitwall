<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Repository\UserRepository;
use App\Security\Authorize;
use Twig\Environment;

class ControlPanelController
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly Environment $twig,
        private readonly Authorize $authorize,
    ) {
    }

    public function index(): void
    {
        $user = $this->authorize->requireAuth();

        echo $this->twig->render('auth/control_panel.twig', [
            'user' => $user,
            'is_logged_in' => true,
            'flash' => $_SESSION['flash'] ?? null,
            'csrf_token' => $_SESSION['csrf_token'] ?? ''
        ]);
        unset($_SESSION['flash']);
    }

    public function updateToken(Request $request): void
    {
        $user = $this->authorize->requireAuth();

        $token = trim((string)$request->post('api_token'));

        if (strlen($token) < 10) {
            $_SESSION['flash'] = "Token looks too short.";
            header('Location: /control_panel');
            exit;
        }

        $this->userRepo->updateApiToken((int)$user['id'], $token);

        $_SESSION['flash'] = "API Token saved successfully.";
        header('Location: /control_panel');
        exit;
    }
}

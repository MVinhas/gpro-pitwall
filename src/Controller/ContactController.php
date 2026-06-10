<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Security\Authorize;
use App\Service\ContactService;
use Twig\Environment;

class ContactController
{
    public function __construct(
        private readonly ContactService $contact,
        private readonly Authorize $authorize,
        private readonly Environment $twig,
    ) {
    }

    public function show(): void
    {
        $user = $this->authorize->requireAuth();
        unset($user['api_token']);

        echo $this->twig->render('contact.twig', [
            'subjects' => ContactService::SUBJECTS,
            'max_message_length' => ContactService::MAX_MESSAGE_LENGTH,
            'old' => $_SESSION['contact_old'] ?? null,
            'flash' => $_SESSION['flash'] ?? null,
            'flash_error' => $_SESSION['flash_error'] ?? null,
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'is_logged_in' => true,
            'user' => $user,
        ]);
        unset($_SESSION['flash'], $_SESSION['flash_error'], $_SESSION['contact_old']);
    }

    public function submit(Request $request): void
    {
        $user = $this->authorize->requireAuth();

        $subject = trim((string) $request->post('subject'));
        $message = (string) $request->post('message');

        $result = $this->contact->submit(
            (int) $user['id'],
            (string) ($user['username'] ?? ''),
            $subject,
            $message,
        );

        if ($result['ok']) {
            $_SESSION['flash'] = 'Message sent — thank you! If a reply is needed, it will go to your account email.';
        } else {
            $_SESSION['flash_error'] = $result['error'];
            // Keep the typed message so a validation/rate-limit error doesn't
            // throw away the user's text.
            $_SESSION['contact_old'] = ['subject' => $subject, 'message' => $message];
        }

        header('Location: /contact');
        exit;
    }
}

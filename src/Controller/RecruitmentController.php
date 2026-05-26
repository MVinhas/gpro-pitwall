<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Security\Authorize;
use App\Service\RecruitmentService;

class RecruitmentController
{
    public function __construct(
        private readonly RecruitmentService $service,
        private readonly Authorize $authorize,
    ) {
    }

    public function analyze(Request $request): void
    {
        $this->authorize->requirePremium();

        try {
            $file = $request->file('driver_csv_file');
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                throw new \Exception("File upload failed or no file selected.");
            }

            $ext = pathinfo((string) $file['name'], PATHINFO_EXTENSION);
            if (strtolower($ext) !== 'csv' && strtolower($ext) !== 'txt') {
                 throw new \Exception("Invalid file type. Only CSV or TXT allowed.");
            }
            $results = $this->service->analyze(
                $file['tmp_name'],
                (string)$request->post('separator', ','),
                (string)$request->post('target_division', 'Rookie'),
                (bool)$request->post('filter_offers')
            );

            $_SESSION['recruitment_results'] = $results;
            $_SESSION['recruitment_error'] = null;
        } catch (\Exception $exception) {
            $_SESSION['recruitment_results'] = [];
            $_SESSION['recruitment_error'] = $exception->getMessage();
        }

        header("Location: /?main_tab=Recruitment Analyzer&page=1");
        exit;
    }
}

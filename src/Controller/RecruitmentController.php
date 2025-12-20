<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Service\RecruitmentService;

class RecruitmentController
{
    public function __construct(
        private readonly RecruitmentService $service
    ) {
    }

    public function analyze(Request $request): void
    {
        try {
            // 1. Security: Check File Upload
            $file = $request->file('driver_csv_file');
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                throw new \Exception("File upload failed or no file selected.");
            }

            // 2. Security: Check File Type (Basic)
            $ext = pathinfo((string) $file['name'], PATHINFO_EXTENSION);
            if (strtolower($ext) !== 'csv' && strtolower($ext) !== 'txt') {
                 throw new \Exception("Invalid file type. Only CSV or TXT allowed.");
            }

            // 3. Process
            $results = $this->service->analyze(
                $file['tmp_name'],
                (string)$request->post('separator', ','),
                (string)$request->post('target_division', 'Rookie'), // Default safe value
                (bool)$request->post('filter_offers')
            );

            // 4. Store Results
            // Note: We store ALL results in session temporarily.
            $_SESSION['recruitment_results'] = $results;
            $_SESSION['recruitment_error'] = null;
        } catch (\Exception $exception) {
            $_SESSION['recruitment_results'] = [];
            $_SESSION['recruitment_error'] = $exception->getMessage();
        }

        // Redirect safely
        header("Location: /?main_tab=Recruitment Analyzer&page=1");
        exit;
    }
}

<?php
namespace App\Controller;

use App\Http\Request;
use App\Service\RecruitmentService;

class RecruitmentController
{
    public function __construct(
        private RecruitmentService $service,
        private PageController $pageController
    ) {}

    public function analyze(Request $request): void
    {
        try {
            $file = $request->file('driver_csv_file');
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                throw new \Exception("File upload failed.");
            }

            $results = $this->service->analyze(
                $file['tmp_name'],
                $request->post('separator', ','),
                $request->post('target_division'),
                (bool)$request->post('filter_offers')
            );

            // STORE IN SESSION
            $_SESSION['recruitment_results'] = $results;
            $_SESSION['recruitment_error'] = null;

        } catch (\Exception $e) {
            $_SESSION['recruitment_results'] = [];
            $_SESSION['recruitment_error'] = $e->getMessage();
        }

        // REDIRECT to Page 1
        header("Location: /?main_tab=Recruitment Analyzer&page=1");
        exit;
    }
}
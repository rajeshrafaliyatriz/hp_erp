<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncDataCron extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-data-cron';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
          
        try {
            // 1. Call your route (API)
            $response = Route::get('/industries', [IndustryController::class, 'index']); // adjust to your actual route

            if ($response->successful()) {
                $data = $response->json();

                // 2. Insert into new table
                foreach ($data as $row) {
                //
                }

                $this->info("Data synced successfully.");
            } else {
                $this->error("Failed to fetch data: ".$response->status());
            }

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }


         try {
            // 2. Call your route (API)
            $response = Route::get('/industry/{id}/departments', [IndustryController::class, 'departments']); // adjust to your actual route

            if ($response->successful()) {
                $data = $response->json();

                // 2. Insert into new table
                foreach ($data as $row) {
                //
                }

                $this->info("Data synced successfully.");
            } else {
                $this->error("Failed to fetch data: ".$response->status());
            }

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }




        try {
            // 3. Call your route (API)
            $response = Route::get('/department/{id}/jobroles', [jobrolecontroller::class, 'getJobRolesByDepartment']); // adjust to your actual route

            if ($response->successful()) {
                $data = $response->json();

                // 2. Insert into new table
                foreach ($data as $row) {
                //
                }

                $this->info("Data synced successfully.");
            } else {
                $this->error("Failed to fetch data: ".$response->status());
            }

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }


         try {
            // 4. Call your route (API)
            $response = Route::get('jobrole/{id}/skills', [jobrolecontroller::class, 'skills']); // adjust to your actual route

            if ($response->successful()) {
                $data = $response->json();

                // 2. Insert into new table
                foreach ($data as $row) {
                //
                }

                $this->info("Data synced successfully.");
            } else {
                $this->error("Failed to fetch data: ".$response->status());
            }

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }



         try {
            // 5. Call your route (API)
            $response = Route::get('skills/search', [jobrolecontroller::class, 'searchskills']); // adjust to your actual route

            if ($response->successful()) {
                $data = $response->json();

                // 2. Insert into new table
                foreach ($data as $row) {
                //
                }

                $this->info("Data synced successfully.");
            } else {
                $this->error("Failed to fetch data: ".$response->status());
            }

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
    }
}
    


<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\MedicationSchedule;
use App\Services\OneSignalSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScheduleMedicationNotifications extends Command
{
    //schedule medication notifications ke OneSignal
    protected $signature = 'medication:schedule-notifications {--days=7}';

    //sync active medication schedules dengan OneSignal Scheduled Messages (batch job untuk 7 hari)
    protected $description = 'Sync active medication schedules dengan OneSignal Scheduled Messages (batch job untuk 7 hari)';

    //schedule command ini untuk dijalankan setiap hari pada pukul 00:00
    public function handle()
    {
        $days = (int) $this->option('days');
        $this->info("Scheduling medication notifications for the next {$days} days...");

        try {
            $this->scheduleNotifications($days);
        } catch (\Exception $e) {
            $this->error('Error scheduling notifications: ' . $e->getMessage());
            Log::error('Error scheduling medications notifications', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    //user dengan medication schedule aktif (start_date <= 7 hari ke depan, end_date null atau >= sekarang) akan disync ke OneSignal sebagai scheduled messages
    private function scheduleNotifications(int $days)
    {
        $service = new OneSignalSyncService();

        // Hitung rentang tanggal untuk schedule (dari sekarang sampai 7 hari ke depan)
        $now = now('UTC');
        $startDate = $now->copy()->startOfDay();
        $endDate = $startDate->copy()->addDays($days)->endOfDay();

        // Cari users dengan medication schedule aktif dalam rentang tanggal tersebut
        $users = User::whereIn('role_user', ['user', 'mahasiswa', 'pegawai', 'pasien', 'patient'])
            ->whereHas('medicationSchedules', function($q) use ($endDate) {
                $q->where('is_active', true)
                  ->whereDate('start_date', '<=', $endDate)
                  ->where(function($q2) {
                      $q2->whereNull('end_date')->orWhereDate('end_date', '>=', now());
                  });
            })
            ->get();

        if ($users->isEmpty()) {
            $this->warn('No users with active medication schedules found');
            return;
        }

        $this->info("Current UTC time: {$now->format('Y-m-d H:i:s')}");
        $this->info("Scheduling for: {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')}");
        $this->info("Found {$users->count()} users with active schedules");

        $totalScheduled = 0;
        $errorCount = 0;

        foreach ($users as $user) {
            // Cari medication schedules aktif untuk user ini dalam rentang tanggal
            $schedules = MedicationSchedule::where('user_id', $user->id)
                ->where('is_active', true)
                ->whereDate('start_date', '<=', $endDate)
                ->where(function($q) {
                    $q->whereNull('end_date')->orWhereDate('end_date', '>=', now());
                })
                ->get();

            foreach ($schedules as $schedule) {
                try {
                    // Sync schedule ke OneSignal sebagai scheduled message
                    if ($service->syncScheduleToOneSignal($schedule)) {
                        $totalScheduled += 2; // First + second reminder
                        $this->line("  ✓ Synced: {$user->email} - {$schedule->medicine->name}");
                    } else {
                        $errorCount++;
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    Log::warning('Failed to schedule medication notifications', [
                        'user_id' => $user->id,
                        'schedule_id' => $schedule->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        $this->info("✅ Successfully scheduled: {$totalScheduled} notifications");
        if ($errorCount > 0) {
            $this->warn("⚠️  Errors: {$errorCount}");
        }
    }
}

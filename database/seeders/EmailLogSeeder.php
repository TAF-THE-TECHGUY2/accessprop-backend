<?php

namespace Database\Seeders;

use App\Models\EmailLog;
use Illuminate\Database\Seeder;

class EmailLogSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/fixtures/email_logs.json');
        $records = json_decode(file_get_contents($path), true);

        foreach ($records as $record) {
            EmailLog::updateOrCreate(
                ['code' => $record['id']],
                [
                    'recipient' => $record['recipient'],
                    'type' => $record['type'],
                    'subject' => $record['subject'],
                    'status' => $record['status'],
                    'sent_at' => $record['sentAt'],
                ],
            );
        }
    }
}

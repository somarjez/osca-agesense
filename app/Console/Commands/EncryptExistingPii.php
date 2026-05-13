<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class EncryptExistingPii extends Command
{
    protected $signature   = 'osca:encrypt-pii {--dry-run : Show what would be encrypted without writing}';
    protected $description = 'One-time migration: encrypt existing plaintext PII fields in senior_citizens.';

    private const FIELDS = ['contact_number', 'place_of_birth', 'philsys_id'];

    public function handle(): int
    {
        $isDry = $this->option('dry-run');

        $rows = DB::table('senior_citizens')
            ->whereNull('deleted_at')
            ->select(array_merge(['id'], self::FIELDS))
            ->get();

        $updated = 0;

        foreach ($rows as $row) {
            $changes = [];

            foreach (self::FIELDS as $field) {
                $value = $row->$field;
                if ($value === null || $value === '') {
                    continue;
                }

                // Skip rows that are already encrypted (valid base64-wrapped JSON from Laravel)
                if ($this->isAlreadyEncrypted($value)) {
                    continue;
                }

                $changes[$field] = Crypt::encryptString($value);
            }

            if (empty($changes)) {
                continue;
            }

            if (!$isDry) {
                DB::table('senior_citizens')
                    ->where('id', $row->id)
                    ->update($changes);
            }

            $updated++;
        }

        $label = $isDry ? '[DRY RUN] Would update' : 'Updated';
        $this->info("{$label} {$updated} senior record(s).");

        return Command::SUCCESS;
    }

    private function isAlreadyEncrypted(string $value): bool
    {
        // Laravel encrypted strings are base64-encoded JSON: {"iv":...,"value":...,"mac":...}
        $decoded = base64_decode($value, strict: true);
        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload)
            && isset($payload['iv'], $payload['value'], $payload['mac']);
    }
}

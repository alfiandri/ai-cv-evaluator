<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Artisan;
use App\Models\{Tenant, User};
use App\Support\TenantContext;
use App\Services\VectorStore;

class BootstrapDemo extends Command
{
    protected $signature = 'app:bootstrap-demo
        {--tenant=acme : Tenant slug or UUID}
        {--name=Dev User : Name for the demo user}
        {--email=dev@example.com : Email for the demo user}
        {--password=secret : Password for the demo user}
        {--force-migrate : Run php artisan migrate --force before bootstrapping}';

    protected $description = 'Create a demo tenant and user, issue an API token, and (optionally) seed vector docs. Prints curl examples.';

    public function handle(VectorStore $vs)
    {
        // 0) Optional migrate (handy on fresh clones)
        if ($this->option('force-migrate')) {
            $this->info('Running migrations...');
            Artisan::call('migrate', ['--force' => true]);
            $this->line(Artisan::output());
        }

        $slug = (string) $this->option('tenant');
        $name = (string) $this->option('name');
        $email = (string) $this->option('email');
        $password = (string) $this->option('password');

        // 1) User
        $user = User::where('email', $email)->first();
        if (!$user) {
            $user = User::create([
                'name'     => $name,
                'email'    => $email,
                'password' => Hash::make($password),
            ]);
            $this->info("Created user: {$user->email}");
        } else {
            $this->info("Using existing user: {$user->email}");
        }

        // 2) Tenant
        $tenant = Tenant::where('slug', $slug)->orWhere('id', $slug)->first();
        if (!$tenant) {
            $tenant = Tenant::create([
                'id'   => Str::uuid(),
                'name' => ucfirst($slug),
                'slug' => $slug,
                'user_id' => $user->id,
            ]);
            $this->info("Created tenant: {$tenant->name} ({$tenant->id})");
        } else {
            $this->info("Using existing tenant: {$tenant->name} ({$tenant->id})");
        }

        // 3) Token
        $token = $user->createToken('api')->plainTextToken;
        $this->info('Issued API token.');

        // 5) Output summary + curl examples
        $this->line('');
        $this->table(
            ['Tenant', 'Tenant ID', 'Tenant Slug', 'User', 'Email'],
            [[$tenant->name, $tenant->id, $tenant->slug, $user->name, $user->email]]
        );

        $this->line('');
        $this->info('Use these headers:');
        $this->line('  Authorization: Bearer ' . $token);
        $this->line('  X-Tenant-ID: ' . $tenant->slug);

        $base = config('app.url', 'http://127.0.0.1:8000');

        $this->line('');
        $this->info('Sample curl: upload (replace paths)');
        $this->line(
            'curl -s -H "Authorization: Bearer ' . $token . '" -H "X-Tenant-ID: ' . $tenant->slug . "\" \\\n" .
                '  -F "cv=@/path/cv.docx" -F "project_report=@/path/project.docx" ' . $base . "/api/upload"
        );

        $this->line('');
        $this->info('Sample curl: evaluate (fill IDs from /upload response)');
        $this->line(
            'curl -s -H "Authorization: Bearer ' . $token . '" -H "X-Tenant-ID: ' . $tenant->slug . '" -H "Content-Type: application/json" \\' . "\n" .
                "  -d '{\"cv_file_id\":\"<CV_ID>\",\"project_file_id\":\"<PRJ_ID>\",\"job_description\":\"...\",\"study_case_brief\":\"...\"}' " .
                $base . '/api/evaluate'
        );

        $this->line('');
        $this->info('Sample curl: result');
        $this->line(
            'curl -s -H "Authorization: Bearer ' . $token . '" -H "X-Tenant-ID: ' . $tenant->slug . '" ' . $base . '/api/result/<JOB_ID>'
        );

        $this->line('');
        $this->info('Done.');
        return self::SUCCESS;
    }
}

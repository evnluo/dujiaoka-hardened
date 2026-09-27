<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class MailSend implements ShouldQueue
{

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务最大尝试次数。
     *
     * @var int
     */
    public $tries = 2;

    /**
     * 任务运行的超时时间。
     *
     * @var int
     */
    public $timeout = 30;

    private $to;

    private $content;

    private $title;


    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(string $to, string $title, string $content)
    {
        $this->to = $to;
        $this->title = $title;
        $this->content = $content;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $body = $this->content;
        $title = $this->title;
        $settings = app(\App\Support\ShopSettings::class)->getAll();
        $driver = $settings['driver'] ?? config('mail.default', 'smtp');
        $smtp = config('mail.mailers.smtp');
        foreach (['host', 'username', 'password'] as $key) {
            $smtp[$key] = $settings[$key] ?? $smtp[$key];
        }
        $smtp['port'] = (int) ($settings['port'] ?? $smtp['port']);
        if (array_key_exists('encryption', $settings)) {
            $smtp['scheme'] = $settings['encryption'] === 'ssl' ? 'smtps' : 'smtp';
            $smtp['require_tls'] = $settings['encryption'] === 'tls';
        }
        config([
            'mail.default' => $driver,
            'mail.mailers.smtp' => $smtp,
            'mail.from.address' => $settings['from_address'] ?? config('mail.from.address'),
            'mail.from.name' => $settings['from_name'] ?? config('mail.from.name'),
        ]);
        // Queue workers must not retain a transport built with an old password.
        Mail::purge($driver);
        $to = $this->to;
        Mail::send(['html' => 'email.mail'], ['body' => $body], function ($message) use ($to, $title){
            $message->to($to)->subject($title);
        });
    }
}

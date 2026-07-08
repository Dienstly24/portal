<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmployeeWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $employeeName;
    public string $employeeEmail;
    public string $plainPassword;
    public array $permissions;

    public function __construct(string $employeeName, string $employeeEmail, string $plainPassword, array $permissions = [])
    {
        $this->employeeName = $employeeName;
        $this->employeeEmail = $employeeEmail;
        $this->plainPassword = $plainPassword;
        $this->permissions = $permissions;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Willkommen bei Dienstly24 – Ihre Zugangsdaten',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.employee_welcome',
        );
    }
}

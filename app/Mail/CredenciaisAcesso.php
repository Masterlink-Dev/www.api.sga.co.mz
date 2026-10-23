<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Email com o endereço do portal, o email de acesso e a senha inicial (enviado uma vez, ao criar a conta)
class CredenciaisAcesso extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'MeuAcesso · O seu acesso ao portal');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.credenciais',
            with: [
                'portal' => config('app.portal_url'),
                'email'  => $this->user->email,
                'senha'  => User::SENHA_INICIAL,
                'papel'  => match ($this->user->tipo) {
                    User::TIPO_SUPER_ADMIN => 'colaborador da equipa MeuAcesso',
                    User::TIPO_ADMIN       => 'administrador',
                    default                => 'funcionário',
                },
            ],
        );
    }
}

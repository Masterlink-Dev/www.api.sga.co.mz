{{-- Email das credenciais: simples e sem imagens externas (alguns servidores do Estado bloqueiam-nas) --}}
<!doctype html>
<html lang="pt">
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5;">
    <h2 style="margin-bottom: 4px;">MeuAcesso</h2>

    <p>Foi criado o seu acesso de <strong>{{ $papel }}</strong> ao portal MeuAcesso.</p>

    <table style="border-collapse: collapse; margin: 16px 0;">
        <tr><td style="padding: 4px 12px 4px 0;">Endereço</td><td><a href="{{ $portal }}">{{ $portal }}</a></td></tr>
        <tr><td style="padding: 4px 12px 4px 0;">Email</td><td><strong>{{ $email }}</strong></td></tr>
        <tr><td style="padding: 4px 12px 4px 0;">Senha inicial</td><td><strong style="font-family: monospace; letter-spacing: 2px;">{{ $senha }}</strong></td></tr>
    </table>

    <p>No primeiro acesso é obrigatório mudar esta senha. Até lá, o portal não deixa fazer mais nada.</p>

    <p style="color: #6b7280; font-size: 13px;">Se não esperava este email, ignore-o.</p>
</body>
</html>

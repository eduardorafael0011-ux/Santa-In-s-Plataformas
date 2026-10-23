# Ativar a recuperação de senha pelo Microsoft 365

O site envia um código temporário usando Microsoft Graph. O segredo do aplicativo deve ficar somente no servidor, em variáveis de ambiente ou em `.private/m365-mail.json`. Nunca o coloque em `index.html`, em outro arquivo servido pelo site ou em mensagens de chat.

## O que o administrador do Microsoft 365 precisa preparar

1. Confirmar uma caixa remetente institucional para os códigos. Pode ser uma caixa dedicada, por exemplo `te@santainesrs.com.br`, se ela existir e puder enviar mensagens.
2. Registrar um aplicativo de locatário único (single tenant) no Microsoft Entra e criar um segredo de cliente. Guardar o segredo no gerenciador de segredos do servidor.
3. No Exchange Online, conceder à aplicação apenas a função `Application Mail.Send`, limitada à caixa remetente. A documentação da Microsoft indica o RBAC for Applications para limitar a aplicação a caixas específicas; permissões de aplicativo concedidas no Entra são aditivas, então não conceda também `Mail.Send` sem escopo no Entra.

Exemplo de configuração do escopo no Exchange Online PowerShell (substitua o endereço remetente e os IDs pelos valores reais):

```powershell
New-ManagementScope -Name "SantaInesResetSender" `
  -RecipientRestrictionFilter "PrimarySmtpAddress -eq 'te@santainesrs.com.br'"

New-ServicePrincipal -AppId "<CLIENT_ID>" `
  -ObjectId "<SERVICE_PRINCIPAL_OBJECT_ID>" `
  -DisplayName "Santa Ines password recovery"

New-ManagementRoleAssignment -Name "SantaInesResetSenderMailSend" `
  -App "<SERVICE_PRINCIPAL_OBJECT_ID>" `
  -Role "Application Mail.Send" `
  -CustomResourceScope "SantaInesResetSender"

Test-ServicePrincipalAuthorization `
  -Identity "<SERVICE_PRINCIPAL_OBJECT_ID>" `
  -Resource "te@santainesrs.com.br"
```

Confirme com `Get-ServicePrincipal` se o objeto do aplicativo já foi cadastrado no Exchange antes de executar `New-ServicePrincipal` novamente. A configuração de permissões deve ser feita por um administrador do Exchange/Microsoft 365.

## Valores privados usados pelo servidor

O servidor aceita estas variáveis de ambiente:

- `M365_TENANT_ID`: ID do locatário Microsoft Entra.
- `M365_CLIENT_ID`: ID do aplicativo.
- `M365_CLIENT_SECRET`: segredo do cliente.
- `RESET_FROM_EMAIL`: endereço da caixa remetente autorizada.

No desenvolvimento local, também é possível guardar esses valores em `.private/m365-mail.json`:

```json
{
  "tenantId": "ID-DO-TENANT",
  "clientId": "ID-DO-APLICATIVO",
  "clientSecret": "SEGREDO-DO-CLIENTE",
  "fromEmail": "te@santainesrs.com.br"
}
```

A pasta `.private/` é ignorada pelo Git, não é servida pelo site e tem acesso restrito no computador local. Em produção, prefira o gerenciador de segredos da hospedagem. Depois de configurar os valores, reinicie o servidor e solicite um código na tela **Esqueci minha senha**.

## Documentação oficial

- [Microsoft Graph: enviar mensagem](https://learn.microsoft.com/graph/api/user-sendmail?view=graph-rest-1.0)
- [Microsoft Graph: referência de permissões](https://learn.microsoft.com/graph/permissions-reference)
- [Exchange Online: RBAC para aplicações](https://learn.microsoft.com/exchange/permissions-exo/application-rbac)
- [Exchange Online: criar escopos de gerenciamento](https://learn.microsoft.com/powershell/module/exchangepowershell/new-managementscope?view=exchange-ps)

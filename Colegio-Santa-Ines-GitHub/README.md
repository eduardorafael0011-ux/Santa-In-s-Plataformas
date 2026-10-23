# Central de Plataformas — Colégio Santa Inês

Site responsivo para reunir tutoriais das plataformas escolares e acompanhar o uso dos materiais por estudantes e responsáveis. O projeto pode rodar no computador durante o desenvolvimento usando Node.js e ser hospedado em Apache com PHP. As duas versões usam as mesmas rotas e arquivos JSON privados; HTML, CSS e JavaScript são executados no navegador nos dois modos.

## Recursos implementados

- Entrada de estudante ou responsável usando matrícula de oito dígitos e nome do estudante, validados contra a lista ativa da escola.
- Catálogo de plataformas com imagem, descrição, PDFs e links de vídeos do YouTube; ícones globais de Google Play, App Store e YouTube.
- A Equipe TE configura a imagem do YouTube junto às imagens das lojas, uma única vez. Cada tutorial de vídeo usa um link HTTPS do YouTube, sem enviar arquivos de vídeo ao servidor.
- Exibição das plataformas conforme as séries selecionadas pela Equipe TE.
- Registro dos horários de entrada e saída de páginas e materiais.
- Painel da Equipe TE para gerenciar plataformas, lista escolar, ano letivo, atividade e contato de suporte.
- Área de professores com e-mail institucional, troca obrigatória de senha inicial, atribuição de plataformas e materiais exclusivos. A visão docente recebe apenas as plataformas atribuídas; o catálogo TE permanece completo.
- Importação de planilha Excel (`.xlsx`) ou CSV; cadastro e edição individuais de estudantes.
- Preparação da passagem de ano com configuração das turmas existentes, sugestões de nova turma e revisão individual. O cadastro existente é atualizado, sem criar uma ficha duplicada.
- Remoção de todos os estudantes da lista ativa mediante digitação de `CONFIRMAR`. Os registros de atividade são preservados.
- Recuperação administrativa de senha local de uso único e opção de recuperação por e-mail Microsoft 365 quando configurada.

## Estrutura do projeto

| Caminho | Descrição |
| --- | --- |
| `index.php` | Página HTML compartilhada pelos servidores Node.js local e PHP. |
| `index.html` | Atalho informativo para abrir o servidor local; não é o aplicativo e não deve ser usado como `file://` para acessar os dados. |
| `assets/site.css` | Estilos compartilhados da interface. |
| `assets/app.js` | Interações no navegador, edição de ícones e vídeos e chamadas às rotas `/api`, compartilhadas nos dois modos. JavaScript continua necessário no cliente. |
| `router.php` | Ponto de entrada da versão PHP: direciona as rotas da API e libera apenas os arquivos públicos necessários. |
| `php/src/ApiRouter.php` | Mapeia método e URL para os serviços da aplicação. |
| `php/src/Http.php` | Leitura de JSON, respostas HTTP e erros padronizados. |
| `php/src/AdminAuth.php` | Sessões da equipe, hash de senha, limitação de tentativas e recuperação. |
| `php/src/TeacherAuth.php` | Contas docentes, senha inicial, troca de senha e sessão separada. |
| `php/src/RosterService.php` | Lista de estudantes, importação, cadastro e passagem de ano. |
| `php/src/SchoolRules.php` | Regras de turma, série e progressão escolar. |
| `php/src/SpreadsheetReader.php` | Leitura da primeira aba de Excel e leitura de CSV. |
| `php/src/ContentService.php` | Validação e gravação de plataformas, materiais e configurações. |
| `php/src/ActivityService.php` | Registro e consulta de atividades dos usuários. |
| `php/src/DataStore.php` | Acesso seguro aos arquivos JSON privados. |
| `.env.example` | Nomes das configurações de ambiente, sem credenciais reais. |
| `server.js` | Backend Node.js para desenvolvimento e prévias no computador. |
| `assets/colegio-santa-ines.png` | Imagem da escola usada no cabeçalho. |
| `reset-admin.php` | Utilitário local para redefinir a senha administrativa antes de hospedar em PHP. |
| `reset-admin.js` | Utilitário local para redefinir a senha no modo Node.js. |
| `.htaccess` | Regras Apache para direcionar a API ao PHP e proteger arquivos privados e de código. |
| `CONFIGURAR-RECUPERACAO-M365.md` | Orientações para configurar o envio de código de recuperação pelo Microsoft 365. |
| `.private/` | Arquivos de dados e configurações confidenciais do servidor. Está excluída do Git. |

Os dois backends leem o mesmo formato e arquivos de dados, usando por padrão `.private/` ou o diretório absoluto configurado por `SANTA_INES_DATA_DIR`. A interface, o CSS e o JavaScript são os mesmos. Senhas são armazenadas como hashes. Sessões usam cookies separados; ao alternar entre Node e PHP, entre novamente na Equipe TE. Mantenha os dados privados fora do acesso público e nunca os inclua em um repositório público.

## Preparar o projeto para GitHub

O repositório não deve conter `.private/`, arquivos `.env`, listas escolares, atividades, contas, senhas ou segredos do Microsoft 365. O `.gitignore` já exclui esses itens. O arquivo `.env.example` contém apenas nomes de variáveis e pode ser versionado; o aplicativo não carrega `.env` automaticamente.

Antes do primeiro commit, confira os arquivos que serão enviados:

```bash
git status --short
git check-ignore -v .private/teachers.json .private/student-roster.json
```

Crie o repositório no GitHub e conecte o endereço remoto escolhido por você. Este diretório não inclui os dados reais de `.private/` no pacote de código. Não publique informações de estudantes ou credenciais administrativas.

### Senha inicial dos professores

O código não contém a senha temporária docente. Configure `SANTA_INES_TEACHER_INITIAL_PASSWORD` no ambiente privado do servidor ou crie `.private/teacher-auth.json` localmente, com o formato `{"initialPassword":"<senha temporária definida pela escola>"}`. O segundo arquivo é ignorado pelo Git. Sem essa configuração, o backend bloqueia cadastro e redefinição docente e informa que falta configurar a senha.

No macOS/Linux, a variável pode ser passada ao iniciar o servidor local:

```bash
SANTA_INES_TEACHER_INITIAL_PASSWORD='defina-aqui-a-senha-temporaria' PORT=3003 node server.js
```

No PowerShell:

```powershell
$env:SANTA_INES_TEACHER_INITIAL_PASSWORD='defina-aqui-a-senha-temporaria'
$env:PORT=3003
node server.js
```

Em produção, configure o segredo no painel da hospedagem. Não inclua a senha real em commits, README, capturas de tela ou repositório público.

## Como iniciar no computador local

### Modo de desenvolvimento (Node.js)

Este modo é o mais simples para continuar trabalhando no computador. Na pasta do projeto, execute:

```bash
PORT=3003 node server.js
```

Abra <http://127.0.0.1:3003/> e deixe o Terminal aberto. A página, os estilos e o JavaScript vêm dos mesmos arquivos usados no modo PHP; não abra `index.html` pelo Finder, pois `file://` não consegue acessar as rotas da API. Se a porta 3003 estiver ocupada, inicie outra porta livre:

```bash
PORT=3004 node server.js
```

No Windows PowerShell, use `$env:PORT=3003; node server.js` (ou `3004` se estiver ocupada). O servidor Node fica restrito a `127.0.0.1`, para uso no próprio computador. Não o exponha diretamente à internet.

### Modo PHP local

Instale PHP 8.1 ou mais recente e habilite as extensões `zip` e `dom` para importar arquivos Excel `.xlsx`. O projeto não precisa de Composer nem de pacotes PHP externos.

No Terminal, entre na pasta do projeto e execute:

```bash
php -S 127.0.0.1:3000 router.php
```

O endereço padrão é <http://127.0.0.1:3000/>. Se essa porta já estiver ocupada, escolha outra:

```bash
php -S 127.0.0.1:3002 router.php
```

Execute o comando na pasta do projeto e deixe o Terminal aberto enquanto usar o site. Para encerrar o servidor embutido, pressione `Ctrl+C`. Em PHP, as sessões ficam em `.private/sessions`; a validade é de 12 horas.

O ponto de entrada do navegador é `index.php`. `router.php` encaminha `/api/...` para o backend PHP e só publica uma lista explícita de arquivos de interface e imagem. Assim, o servidor embutido não expõe os diretórios de código PHP ou `.private/` como arquivos estáticos.

### Compatibilidade de senha entre os modos

O formato de senha atual é PBKDF2-SHA256 e pode ser validado pelo Node.js e pelo PHP. Contas antigas que usam `scrypt` são convertidas automaticamente ao fazer login no modo Node. Para preparar a conta antes de ir direto para PHP, redefina a senha no computador com:

```bash
php reset-admin.php admin@escola.edu.br
```

O utilitário pede a nova senha sem exibir os caracteres e grava o hash compatível em `.private/admins.json`. O utilitário Node `node reset-admin.js admin@escola.edu.br` também grava esse formato. Passe o e-mail administrativo como argumento; não há uma conta individual definida no código. Faça uma cópia segura de `.private/` antes de redefinir a senha.

Os cookies de sessão PHP e Node são distintos; ao trocar de modo, entre novamente na Equipe TE. Evite operar os dois servidores ao mesmo tempo sobre os arquivos `.private/`, principalmente durante importações e alterações administrativas.

## Hospedar no servidor do colégio (Apache + PHP)

1. Publique os arquivos do projeto no diretório do site ou em um subdomínio dedicado. O `.htaccess` direciona `/api/...` para o PHP, escolhe a página compartilhada como inicial e nega acesso direto a dados e arquivos internos. A hospedagem precisa permitir `mod_rewrite` e `.htaccess`.
2. Use PHP 8.1 ou mais recente, com `zip` e `dom` habilitados. Configure o domínio com HTTPS e aponte a raiz pública para esta pasta (o app espera ser servido na raiz do domínio).
3. Configure `SANTA_INES_DATA_DIR` com um caminho absoluto, persistente e fora da raiz pública do site (por exemplo, uma pasta privada da conta de hospedagem). O PHP precisa ter leitura e escrita nessa pasta. Ela guarda a lista de estudantes, plataformas, atividades, sessões e contas administrativas. Se o provedor não permitir uma pasta fora da raiz pública, use `.private/` e confirme que as regras do `.htaccess` estão ativas antes de colocar dados reais.
4. Antes da abertura às famílias, prepare a conta administrativa com `php reset-admin.php admin@escola.edu.br` em ambiente seguro. Configure `APP_ENV=production`, `SANTA_INES_TEACHER_INITIAL_PASSWORD` e as credenciais do Microsoft 365 no ambiente privado do servidor, e faça uma cópia de segurança protegida.
5. Faça uma importação inicial da planilha oficial e confira login, PDFs, vídeos, salvamento de plataforma e logs usando a URL HTTPS de produção.

O servidor local e o hospedado executam o mesmo projeto e leem o mesmo formato de dados, mas mantêm arquivos em discos diferentes: mudanças feitas no computador não aparecem automaticamente no servidor do colégio. Transfira somente o que for necessário por canal seguro; a lista escolar e os dados de atividade são informações pessoais. Nunca envie `.private/` por e-mail, repositório público ou serviço de hospedagem de arquivos aberto. Em hospedagem Nginx, as regras do `.htaccess` não são lidas; o administrador precisa configurar regras equivalentes no servidor.

## Planilha de estudantes

Use as colunas abaixo, nesta ordem. A primeira linha pode conter os títulos.

| Coluna | Campo |
| --- | --- |
| A | Matrícula numérica com oito dígitos |
| B | Nome do estudante |
| C | Turma |

São aceitos `.xlsx` e CSV, com limite de 25 MB e até 20.000 estudantes. Matrículas repetidas ou linhas incompletas são rejeitadas. Matrículas menores que oito dígitos podem ser completadas com zeros à esquerda. Turmas dos Anos Iniciais e Finais usam códigos como `11`, `63` ou `91`; Ensino Médio usa códigos como `1A`, `2B` ou `3C`.

## API principal

As rotas administrativas exigem sessão válida da Equipe TE.

| Método e rota | Finalidade |
| --- | --- |
| `POST /api/student/login` | Valida matrícula e nome na lista ativa. |
| `POST /api/teacher/login`, `/api/teacher/password`, `/api/teacher/logout` | Acesso docente e troca obrigatória da senha inicial. |
| `GET /api/teacher/content` | Retorna apenas as plataformas atribuídas ao professor autenticado. |
| `GET/POST /api/admin/teachers`, `POST /api/admin/teachers/reset` | Cadastro, atribuição de plataformas e redefinição de senha docente pela Equipe TE. |
| `GET /api/session`, `POST /api/login`, `POST /api/logout` | Consulta, inicia e encerra a sessão administrativa. |
| `POST /api/password-reset/request` e `POST /api/password-reset/complete` | Envia e valida o código de recuperação por e-mail. |
| `GET/POST /api/password-reset/local` | Disponibiliza redefinição local de uso único apenas em desenvolvimento local. |
| `GET /api/content` e `PUT /api/admin/content` | Consulta e salva plataformas, suporte e ícones. |
| `POST /api/admin/roster/import` | Importa a planilha oficial. |
| `GET /api/admin/roster/students` | Pesquisa e pagina a lista de estudantes. |
| `POST /api/admin/roster/student` | Adiciona ou edita um estudante. |
| `DELETE /api/admin/roster/students` | Remove os cadastros ativos da lista. |
| `GET /api/admin/roster/year-plan` | Prepara a revisão para o próximo ano. |
| `PUT /api/admin/roster/year-plan/classes` | Salva as turmas existentes no próximo ano. |
| `POST /api/admin/roster/year-plan/apply` | Aplica a passagem revisada. |
| `POST /api/activities` e `PATCH /api/activities/:id/exit` | Registra início e saída de uma atividade. |
| `GET /api/admin/activities` e `DELETE /api/admin/activities` | Consulta ou apaga registros de atividade. |

### Como o código se organiza

Uma solicitação chega em `router.php`. Se for uma chamada `/api/...`, `ApiRouter` identifica a rota, valida a sessão quando a ação é administrativa e encaminha o trabalho ao serviço adequado. O serviço aplica as regras do domínio e usa `DataStore` para ler ou gravar os arquivos privados. A resposta volta como JSON padronizado por `Http`. A interface em `assets/app.js` consome esse JSON e atualiza a tela; `assets/site.css` concentra os estilos.

Essa separação mantém regras escolares em `SchoolRules`, persistência em `DataStore`, operações por recurso em `*Service.php` e detalhes HTTP em `ApiRouter`/`Http`. Ao adicionar uma função, prefira criar ou ampliar um serviço e deixe a rota responsável apenas por autenticação, leitura da entrada e resposta.

## Cuidados de operação

- Faça cópias de segurança da pasta `.private/` em local protegido. Ela contém dados pessoais dos estudantes e dados de acesso da equipe.
- `.private/` está no `.gitignore`; verifique isso antes de criar um repositório ou backup externo.
- Os arquivos JSON são armazenados no disco local. Não há banco de dados externo, replicação ou sincronização entre várias instâncias.
- O servidor Node local escuta apenas em `127.0.0.1`, adequado ao uso no próprio computador. Para hospedagem, use Apache + PHP com HTTPS, armazenamento persistente e estratégia de backup. A configuração local não publica o site. Os dados do computador e do servidor escolar ficam em discos diferentes e não se sincronizam automaticamente.
- Configure credenciais do Microsoft 365 por variáveis de ambiente ou em arquivo privado conforme `CONFIGURAR-RECUPERACAO-M365.md`. Nunca coloque segredos no `index.html` ou neste README.
- A recuperação local da senha só funciona em desenvolvimento local e pode ser usada uma vez. Para recuperação por e-mail, configure o Microsoft 365.

## Prévia de imagens

O campo `#platformPaste` aceita imagens coladas com `Ctrl+V`. A prévia mantém a proporção original para evitar cortes e fica centralizada no campo de edição.

<?php
// Nik-Negar — secrets for THIS site. Copy to config.php on the server and fill in.
// Never commit config.php. The owner pastes the tokens; nobody else needs to see them.

// Telegram bot token from @BotFather (one bot per site, or per client).
const TG_BOT_TOKEN = 'PASTE_BOT_TOKEN';

// GitHub fine-grained token: only the content repo, permission "Contents: Read and write".
// Needed for: private content repo, editing keywords from the panel, the "new article" button.
const GH_TOKEN = '';

// true = every draft waits for approval in Telegram (recommended at the start). Can be changed later from the panel.
const REQUIRE_APPROVAL = true;

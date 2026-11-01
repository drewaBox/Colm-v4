<?php
// Student self-registration is closed: requesters use a guest session from the landing page (api/guest_start.php),
// and the information they confirm there is saved as their record under Students in the admin portal.
require_once __DIR__ . '/../backend/auth.php';
register_error_handlers();
fail('Account registration is no longer available. Use "Request a Document" on the home page.', 410);
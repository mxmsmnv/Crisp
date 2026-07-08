<?php

/**
 * Crisp Live Chat for ProcessWire
 *
 * Integrates the Crisp live chat widget into ProcessWire sites.
 * Automatically syncs logged-in user data (email, name) with Crisp.
 *
 * @author  Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @version 1.0.0
 * @license MIT
 */

class Crisp extends WireData implements Module, ConfigurableModule {

    /**
     * Module info required by ProcessWire
     */
    public static function getModuleInfo() {
        return [
            'title'    => 'Crisp Live Chat',
            'version'  => 100,
            'summary'  => 'Adds the Crisp live chat widget to your ProcessWire site. Supports automatic user identity sync and HMAC verification.',
            'author'   => 'Maxim Semenov',
            'href'     => 'https://smnv.org',
            'singular' => true,
            'autoload' => true,
            'icon'     => 'comment',
        ];
    }

    /**
     * Default configuration values
     */
    public static function getDefaultConfig() {
        return [
            'website_id'        => '',
            'website_verify'    => '',
            'templates_mode'    => 'all',   // 'all' | 'include' | 'exclude'
            'templates_select'  => [],      // array of template names
            'logged_in_only'    => 0,       // show only to logged-in users
            'hidden_roles'      => [],      // roles to hide widget from
            'widget_position'   => '',      // '' | 'bottom_right' | 'bottom_left'
            'widget_color'      => '',      // crisp color name e.g. pink
            'chat_autoopen'     => 0,       // auto-open chat on page load
            'locale_mode'       => 'auto',  // 'auto' | 'force' | 'browser'
            'locale_force'      => '',      // e.g. 'en', 'fr', 'de'
            'custom_launcher_enabled' => 0,  // hide default bubble, show our own icon-based button instead
            'custom_launcher_icon'    => '', // URL of the image to use as the launcher icon
            'sync_segments'     => 0,      // tag the Crisp session with the user's roles
            'sync_page_data'    => 0,      // send current page URL/title/template as session data
            'sync_phone_field'  => '',     // name of the PW user field holding a phone number
            'sync_company_field' => '',    // name of the PW user field holding a company name
        ];
    }

    /**
     * @var bool guards against double-injection (e.g. nested Page::render calls)
     */
    protected $injected = false;

    /**
     * Bootstrap — only the admin OAuth-callback hook lives here, since it doesn't
     * depend on which page is being viewed.
     */
    public function init() {
        $this->addHookAfter('ProcessModule::executeEdit', $this, 'handleCrispCallback');
        $this->addHookBefore('Session::logout', $this, 'flagSessionReset');
    }

    /**
     * Mark the next page load for a Crisp session:reset. Logout destroys the PW
     * session, so we can't use it to carry this flag across the redirect — a short-lived
     * cookie is the only thing that survives. Without this, a new visitor on a shared
     * device would see the previous user's conversation history in the widget.
     */
    public function flagSessionReset(HookEvent $event) {
        if (headers_sent()) return;
        setcookie('crisp_reset', '1', [
            'expires'  => time() + 60,
            'path'     => '/',
            'secure'   => (bool) $this->wire('config')->https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Attach the render hook to the specific page instance being viewed (mirrors the
     * Cookie module's approach) instead of hooking Page::render globally. This avoids
     * duplicate injection from nested/embedded renders (e.g. PageTable) and lets us
     * skip attaching the hook entirely when none of the visibility rules match.
     */
    public function ready() {
        $page = $this->wire('page');
        if (!$page || !$page->id) return;
        if ($page->template == 'admin') return;
        if (empty($this->website_id)) return;

        // Template filter
        $mode     = $this->templates_mode ?: 'all';
        $selected = is_array($this->templates_select) ? $this->templates_select : [];
        $tplName  = (string) $page->template->name;

        if ($mode === 'include' && count($selected) > 0 && !in_array($tplName, $selected)) return;
        if ($mode === 'exclude' && count($selected) > 0 && in_array($tplName, $selected)) return;

        // Logged-in only filter
        $user = $this->wire('user');
        if ($this->logged_in_only && !$user->isLoggedin()) return;

        // Hidden roles filter
        $hiddenRoles = is_array($this->hidden_roles) ? $this->hidden_roles : [];
        if (count($hiddenRoles) > 0 && $user->isLoggedin()) {
            foreach ($hiddenRoles as $roleName) {
                if ($user->hasRole($roleName)) return;
            }
        }

        $page->addHookAfter('render', $this, 'injectCrispScript');
    }

    /**
     * When Crisp redirects back after OAuth, it appends GET params:
     *   crisp_website_id=xxx, crisp_verify=yyy and our own crisp_csrf=<token>
     * The token was minted and stored in the session when the "connect via Crisp"
     * link was generated (see getModuleConfigInputfields). Since this is a plain
     * GET request, without it anyone able to lure a superuser into clicking a
     * crafted link could silently repoint the widget (and all visitor identity
     * data) at an attacker-controlled Crisp website — verifying it closes that hole.
     */
    public function handleCrispCallback(HookEvent $event) {
        $input   = $this->wire('input');
        $session = $this->wire('session');
        $modules = $this->wire('modules');

        if ((string) $input->get('name') !== 'Crisp') return;

        $newId = (string) $input->get->text('crisp_website_id');
        if (empty($newId)) return;

        $expectedToken = (string) $session->get('crisp_oauth_token');
        $givenToken    = (string) $input->get->text('crisp_csrf');
        if (empty($expectedToken) || empty($givenToken) || !hash_equals($expectedToken, $givenToken)) {
            $session->error(__('Crisp connection request could not be verified. Please try connecting again.'));
            return;
        }
        $session->remove('crisp_oauth_token');

        if (!$this->wire('user')->isSuperuser()) return;

        $newVerify = (string) $input->get->text('crisp_verify');

        $configData = $modules->getModuleConfigData('Crisp');
        $configData['website_id'] = $this->wire('sanitizer')->text($newId);
        if (!empty($newVerify)) {
            $configData['website_verify'] = $this->wire('sanitizer')->text($newVerify);
        }
        $modules->saveModuleConfigData('Crisp', $configData);

        $adminUrl = rtrim($this->wire('config')->urls->httpAdmin, '/');
        $session->redirect($adminUrl . '/module/edit?name=Crisp&crisp_saved=1');
    }

    /**
     * Inject the Crisp script into the page (called only once per request,
     * only when ready() has already matched all visibility rules)
     */
    public function injectCrispScript(HookEvent $event) {
        if ($this->injected) return;

        $out = $event->return;
        if (!is_string($out) || strripos($out, '</body>') === false) return;

        $this->injected = true;
        $website_id = $this->website_id;
        $page = $this->wire('page');

        // Consume the one-shot logout flag (see flagSessionReset) so the widget
        // doesn't carry over the previous visitor's conversation on this device.
        $reset_js = '';
        if (!empty($_COOKIE['crisp_reset'])) {
            $reset_js = "\$crisp.push(['do', 'session:reset']);";
            setcookie('crisp_reset', '', ['expires' => time() - 3600, 'path' => '/']);
        }

        // Locale handling
        $localeMode  = $this->locale_mode ?: 'auto';
        $localeForce = trim($this->locale_force ?: '');
        if ($localeMode === 'browser') {
            $locale = null;
        } elseif ($localeMode === 'force' && !empty($localeForce)) {
            $locale = preg_replace('/[^a-zA-Z\-]/', '', $localeForce);
        } else {
            $locale = $this->getLocale();
        }
        $user_js = $this->syncUser();

        $position  = $this->widget_position ?: '';
        $color     = $this->widget_color ?: '';
        $autoopen  = (int) $this->chat_autoopen;

        $runtime_extras = '';
        if ($position === 'bottom_left') {
            $runtime_extras .= "
    window.CRISP_RUNTIME_CONFIG.position = 'left';";
        }
        if (!empty($color)) {
            $safeColor = preg_replace('/[^a-z]/', '', strtolower($color));
            $runtime_extras .= "
    window.CRISP_RUNTIME_CONFIG.color_theme = " . json_encode($safeColor) . ";";
        }

        $autoopen_js = $autoopen ? "
  \$crisp.push(['do', 'chat:open']);" : '';

        if ($locale !== null) {
            $locale_js = "if (!window.CRISP_RUNTIME_CONFIG.locale) { window.CRISP_RUNTIME_CONFIG.locale = " . json_encode($locale) . "; }";
        } else {
            $locale_js = '';
        }

        $context_js  = $this->buildSessionContextJs($page);
        $launcher_js = $this->buildCustomLauncherJs($position);

        // All dynamic values are passed through json_encode so nothing (including an
        // admin-supplied website_id containing a quote) can break out of the <script> block.
        $script = <<<HTML
<script>
  window.\$crisp = [];
  if (!window.CRISP_RUNTIME_CONFIG) {
    window.CRISP_RUNTIME_CONFIG = {};
  }
  {$locale_js}{$runtime_extras}
  CRISP_WEBSITE_ID = {$this->jsonJs($website_id)};
  {$reset_js}{$user_js}{$context_js}{$launcher_js}{$autoopen_js}
</script>
<script async src="https://client.crisp.chat/l.js"></script>
HTML;

        // Insert before the last </body> only, rather than replacing every occurrence
        $pos = strripos($out, '</body>');
        $event->return = substr($out, 0, $pos) . $script . substr($out, $pos);
    }

    /**
     * Sync logged-in ProcessWire user data with Crisp
     */
    protected function syncUser() {
        $output = '';
        $user = $this->wire('user');

        if (!$user || !$user->isLoggedin()) return $output;

        $email  = (string) $user->email;
        $name   = (string) $user->name;
        $verify = $this->website_verify;

        if (!empty($email)) {
            if (!empty($verify)) {
                // HMAC must be computed on the raw email — Crisp recomputes it the same way
                // to verify identity, so hashing an escaped/altered value would break verification.
                $hmac = hash_hmac('sha256', $email, $verify);
                $output .= "\$crisp.push(['set', 'user:email', [{$this->jsonJs($email)}, {$this->jsonJs($hmac)}]]);";
            } else {
                $output .= "\$crisp.push(['set', 'user:email', {$this->jsonJs($email)}]);";
            }
        }

        if (!empty($name)) {
            $output .= "\$crisp.push(['set', 'user:nickname', {$this->jsonJs($name)}]);";
        }

        $phoneField = trim((string) $this->sync_phone_field);
        if (!empty($phoneField) && $user->hasField($phoneField)) {
            $phone = (string) $user->get($phoneField);
            if (!empty($phone)) {
                $output .= "\$crisp.push(['set', 'user:phone', [{$this->jsonJs($phone)}]]);";
            }
        }

        $companyField = trim((string) $this->sync_company_field);
        if (!empty($companyField) && $user->hasField($companyField)) {
            $company = (string) $user->get($companyField);
            if (!empty($company)) {
                $output .= "\$crisp.push(['set', 'user:company', [{$this->jsonJs($company)}]]);";
            }
        }

        return $output;
    }

    /**
     * Tag the session with the user's roles and/or the current page context, so
     * operators in the Crisp inbox see who/what they're dealing with at a glance.
     */
    protected function buildSessionContextJs($page) {
        $output = '';
        $user = $this->wire('user');

        if ($this->sync_segments) {
            $segments = [];
            if ($user->isLoggedin()) {
                foreach ($user->roles as $role) {
                    if ($role->name !== 'guest') $segments[] = $role->name;
                }
            }
            if (empty($segments)) $segments[] = 'guest';
            $segmentsJson = json_encode(array_values($segments), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $output .= "\$crisp.push(['set', 'session:segments', [{$segmentsJson}, true]]);";
        }

        if ($this->sync_page_data && $page && $page->id) {
            $pairs = [
                ['page_url', (string) $page->httpUrl],
                ['page_title', (string) $page->title],
                ['page_template', (string) $page->template->name],
            ];
            $pairsJson = json_encode($pairs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $output .= "\$crisp.push(['set', 'session:data', [{$pairsJson}]]);";
        }

        return $output;
    }

    /**
     * When a custom launcher icon is configured, hide Crisp's default bubble and build
     * our own round launcher button showing that icon instead — Crisp itself has no
     * API to swap the default bubble's icon, so the module owns the whole button.
     */
    protected function buildCustomLauncherJs($position) {
        if (empty($this->custom_launcher_enabled)) return '';

        $iconUrl = trim((string) $this->custom_launcher_icon);
        if ($iconUrl === '') return '';

        $iconJs  = $this->jsonJs($iconUrl);
        $sideCss = $position === 'bottom_left' ? 'left:20px;' : 'right:20px;';

        return "\$crisp.push(['do', 'chat:hide']);"
            . "(function() {"
            . "var btn = document.createElement('button');"
            . "btn.type = 'button';"
            . "btn.setAttribute('aria-label', 'Open chat');"
            . "btn.style.cssText = 'position:fixed;bottom:20px;{$sideCss}width:60px;height:60px;padding:0;border:none;border-radius:50%;cursor:pointer;background-color:#fff;background-repeat:no-repeat;background-position:center;background-size:60%;box-shadow:0 4px 12px rgba(0,0,0,.2);z-index:999999;';"
            . "btn.style.backgroundImage = 'url(' + {$iconJs} + ')';"
            . "btn.addEventListener('click', function() {"
            . "\$crisp.push(['do', 'chat:show']); \$crisp.push(['do', 'chat:open']);"
            . "});"
            . "document.body.appendChild(btn);"
            . "})();";
    }

    /**
     * Encode a PHP value as a safe JS literal for inline <script> output
     */
    protected function jsonJs($value) {
        return json_encode((string) $value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /**
     * Get a Crisp-compatible locale string from ProcessWire's language
     */
    protected function getLocale() {
        $languages = $this->wire('languages');
        if ($languages) {
            $lang = $this->wire('user')->language;
            if ($lang && $lang->id) {
                $locale = strtolower($lang->name);
                $locale = str_replace('_', '-', $locale);
                // Normalize: keep only "xx-xx" part
                $locale = preg_replace('/([a-z]{2}-[a-z]{2})(-.*)?/', '$1', $locale);
                return $locale;
            }
        }
        // Fallback: use PHP locale
        $locale = strtolower(substr(setlocale(LC_ALL, 0), 0, 5));
        $locale = str_replace('_', '-', $locale);
        return $locale ?: 'en';
    }

    /**
     * Module config fields (shown in Admin > Modules > Configure)
     */
    public static function getModuleConfigInputfields(array $data) {
        $data    = array_merge(self::getDefaultConfig(), $data);
        $modules = wire('modules');

        $inputfields = new InputfieldWrapper();

        // --- Tab: Connection ---
        $tConn = new InputfieldWrapper();
        $tConn->attr('id', 'crisp_tab_connection');
        $tConn->attr('title', __('Connection'));
        $tConn->addClass('WireTab');

        $website_id = $data['website_id'];
        if (!empty($website_id)) {
            /** @var InputfieldMarkup $fm */
            $fm = $modules->get('InputfieldMarkup');
            $fm->label = __('Crisp Status');
            $fm->value = '
                <div style="background:#f0fff4;border:1px solid #38a169;border-radius:6px;padding:16px 20px;color:#276749;font-size:14px;">
                    ✅ <strong>' . __('Crisp is connected!') . '</strong>
                    &nbsp;&nbsp;
                    <a href="https://app.crisp.chat/website/' . htmlspecialchars($website_id) . '/inbox/" target="_blank"
                       style="color:#276749;font-weight:bold;">💬 ' . __('Open Inbox') . '</a>
                    &nbsp;&nbsp;
                    <a href="https://app.crisp.chat/settings/website/' . htmlspecialchars($website_id) . '/" target="_blank"
                       style="color:#276749;">⚙️ ' . __('Settings') . '</a>
                </div>';
            $tConn->add($fm);
        } else {
            /** @var InputfieldMarkup $fm */
            $fm = $modules->get('InputfieldMarkup');
            $fm->label = __('Get started');

            // Build callback URL without double slashes
            // urls->admin already includes the full path like /narzan/
            // The csrf token is echoed back by Crisp as part of this URL and verified
            // in handleCrispCallback() to make sure the save request actually originated here.
            $csrfToken = bin2hex(random_bytes(20));
            wire('session')->set('crisp_oauth_token', $csrfToken);

            $adminUrl    = rtrim(wire('config')->urls->httpAdmin, '/');
            $callbackUrl = $adminUrl . '/module/edit?name=Crisp&crisp_csrf=' . $csrfToken;
            $callback    = urlencode($callbackUrl);

            $adminEmail  = wire('user')->email;
            $adminName   = wire('user')->name;
            $siteDomain  = wire('config')->httpHost;

            $crispLink = htmlspecialchars(
                "https://app.crisp.chat/initiate/plugin/aca0046c-356c-428f-8eeb-063014c6a278"
                . "?payload={$callback}"
                . "&user_email=" . urlencode($adminEmail)
                . "&user_name=" . urlencode($adminName)
                . "&website_name=" . urlencode($siteDomain)
                . "&website_domain=" . urlencode($siteDomain)
            );
            $fm->value = '
                <div style="background:#fff5f5;border:1px solid #fc8181;border-radius:6px;padding:16px 20px;color:#742a2a;font-size:14px;">
                    ⚠️ <strong>' . __('Crisp is not connected yet.') . '</strong>
                    ' . __('Paste your Website ID below, or') . '
                    <a href="' . $crispLink . '" target="_blank" style="color:#c53030;font-weight:bold;">
                        ' . __('connect via Crisp →') . '
                    </a>
                </div>';
            $tConn->add($fm);
        }

        /** @var InputfieldText $f */
        $f = $modules->get('InputfieldText');
        $f->attr('name', 'website_id');
        $f->attr('value', $data['website_id']);
        $f->label = __('Crisp Website ID');
        $f->description = __('Crisp app → Settings → Workspace Settings → Setup & Integrations → Website ID');
        $f->notes = __('Example: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
        $f->columnWidth = 100;
        $f->required = false;
        $tConn->add($f);

        /** @var InputfieldText $f2 */
        $f2 = $modules->get('InputfieldText');
        $f2->attr('name', 'website_verify');
        $f2->attr('value', $data['website_verify']);
        $f2->label = __('User Verification Secret (optional)');
        $f2->description = __('Crisp app → Settings → Workspace Settings → Advanced configuration → Identity Verification. Requires a paid plan (Mini or higher). Leave blank if not used.');
        $f2->notes = __('Leave blank if you are not using Crisp user verification.');
        $f2->columnWidth = 100;
        $tConn->add($f2);

        $inputfields->add($tConn);

        // --- Tab: Visibility ---
        $tVis = new InputfieldWrapper();
        $tVis->attr('id', 'crisp_tab_visibility');
        $tVis->attr('title', __('Visibility'));
        $tVis->addClass('WireTab');

        // --- Template Filter ---
        $fs = $modules->get('InputfieldFieldset');
        $fs->label = __('Template Visibility');
        $fs->description = __('Control on which templates the Crisp widget is shown.');
        $fs->collapsed = Inputfield::collapsedNo;

        $fmode = $modules->get('InputfieldRadios');
        $fmode->attr('name', 'templates_mode');
        $fmode->attr('value', isset($data['templates_mode']) ? $data['templates_mode'] : 'all');
        $fmode->label = __('Show widget on');
        $fmode->addOption('all',     __('All templates'));
        $fmode->addOption('include', __('Only selected templates'));
        $fmode->addOption('exclude', __('All templates except selected'));
        $fmode->optionColumns = 1;
        $fs->add($fmode);

        // Build list of all non-system templates
        $tplOptions = [];
        foreach (wire('templates') as $tpl) {
            if ($tpl->flags & Template::flagSystem) continue;
            $tplOptions[$tpl->name] = $tpl->name;
        }

        $fsel = $modules->get('InputfieldCheckboxes');
        $fsel->attr('name', 'templates_select');
        $selectedTpls = isset($data['templates_select']) ? $data['templates_select'] : [];
        if (!is_array($selectedTpls)) $selectedTpls = [];
        $fsel->attr('value', $selectedTpls);
        $fsel->label = __('Templates');
        $fsel->description = __('Select templates for the rule above. Ignored when "All templates" is chosen.');
        foreach ($tplOptions as $tName => $tLabel) {
            $fsel->addOption($tName, $tLabel);
        }
        $fsel->optionColumns = 4;
        $fsel->showIf = 'templates_mode!=all';
        $fs->add($fsel);

        $tVis->add($fs);

        // --- Visibility Rules ---
        $fv = $modules->get('InputfieldFieldset');
        $fv->label = __('Visibility Rules');
        $fv->collapsed = Inputfield::collapsedNo;

        $fli = $modules->get('InputfieldCheckbox');
        $fli->attr('name', 'logged_in_only');
        $fli->attr('value', 1);
        $fli->attr('checked', !empty($data['logged_in_only']) ? 'checked' : '');
        $fli->label = __('Show only to logged-in users');
        $fli->description = __('When enabled, the widget is hidden from guests and visible only to authenticated users.');
        $fv->add($fli);

        $roles = wire('roles')->find('name!=guest');
        $froles = $modules->get('InputfieldCheckboxes');
        $froles->attr('name', 'hidden_roles');
        $hiddenRolesVal = isset($data['hidden_roles']) ? $data['hidden_roles'] : [];
        if (!is_array($hiddenRolesVal)) $hiddenRolesVal = [];
        $froles->attr('value', $hiddenRolesVal);
        $froles->label = __('Hide widget from these roles');
        $froles->description = __('Logged-in users with any of the selected roles will not see the Crisp widget.');
        foreach ($roles as $role) {
            $froles->addOption($role->name, $role->name);
        }
        $froles->optionColumns = 4;
        $fv->add($froles);

        $tVis->add($fv);

        $inputfields->add($tVis);

        // --- Tab: Appearance ---
        $tApp = new InputfieldWrapper();
        $tApp->attr('id', 'crisp_tab_appearance');
        $tApp->attr('title', __('Appearance'));
        $tApp->addClass('WireTab');

        // --- Widget Appearance ---
        $fa = $modules->get('InputfieldFieldset');
        $fa->label = __('Widget Appearance');
        $fa->collapsed = Inputfield::collapsedNo;

        $fpos = $modules->get('InputfieldRadios');
        $fpos->attr('name', 'widget_position');
        $fpos->attr('value', isset($data['widget_position']) ? $data['widget_position'] : '');
        $fpos->label = __('Widget Position');
        $fpos->addOption('', __('Bottom Right (default)'));
        $fpos->addOption('bottom_left', __('Bottom Left'));
        $fpos->optionColumns = 1;
        $fa->add($fpos);

        $fcol = $modules->get('InputfieldSelect');
        $fcol->attr('name', 'widget_color');
        $fcol->attr('value', isset($data['widget_color']) ? $data['widget_color'] : '');
        $fcol->label = __('Widget Color');
        $fcol->description = __('Accent color for the chatbox button and header. Overrides the color set in Crisp app.');
        $fcol->notes = __('Leave blank to use the color from Crisp app -> Chatbox Settings -> Chatbox Appearance.');
        $fcol->columnWidth = 50;
        $fcol->addOption('',        __('- Use Crisp app setting -'));
        $fcol->addOption('default', 'Default (Blue)');
        $fcol->addOption('amber',   'Amber');
        $fcol->addOption('blue',    'Blue');
        $fcol->addOption('green',   'Green');
        $fcol->addOption('indigo',  'Indigo');
        $fcol->addOption('orange',  'Orange');
        $fcol->addOption('pink',    'Pink');
        $fcol->addOption('purple',  'Purple');
        $fcol->addOption('red',     'Red');
        $fcol->addOption('teal',    'Teal');
        $fa->add($fcol);

        $fauto = $modules->get('InputfieldCheckbox');
        $fauto->attr('name', 'chat_autoopen');
        $fauto->attr('value', 1);
        $fauto->attr('checked', !empty($data['chat_autoopen']) ? 'checked' : '');
        $fauto->label = __('Auto-open chat on page load');
        $fauto->description = __('Automatically opens the chat window when the page loads.');
        $fauto->columnWidth = 50;
        $fa->add($fauto);

        // --- Custom Launcher ---
        $fcle = $modules->get('InputfieldCheckbox');
        $fcle->attr('name', 'custom_launcher_enabled');
        $fcle->attr('value', 1);
        $fcle->attr('checked', !empty($data['custom_launcher_enabled']) ? 'checked' : '');
        $fcle->label = __('Use a custom launcher icon');
        $fcle->description = __('Hides the default Crisp bubble and shows your own icon instead — the module builds and positions the button for you.');
        $fcle->notes = __('Useful if you want full control over the launcher icon — Crisp only lets you recolor the bubble, not replace its icon.');
        $fa->add($fcle);

        $fcli = $modules->get('InputfieldURL');
        $fcli->attr('name', 'custom_launcher_icon');
        $fcli->attr('value', isset($data['custom_launcher_icon']) ? $data['custom_launcher_icon'] : '');
        $fcli->attr('placeholder', 'https://example.com/chat-icon.png');
        $fcli->label = __('Custom Launcher Icon URL');
        $fcli->description = __('Direct URL to the image (PNG/SVG) to show as the launcher icon.');
        $fcli->showIf = 'custom_launcher_enabled=1';
        $fa->add($fcli);

        // --- Locale ---
        $flm = $modules->get('InputfieldRadios');
        $flm->attr('name', 'locale_mode');
        $flm->attr('value', isset($data['locale_mode']) ? $data['locale_mode'] : 'auto');
        $flm->label = __('Chat Language');
        $flm->addOption('auto',    __('Auto — detect from ProcessWire language'));
        $flm->addOption('browser', __('Browser default — let Crisp detect from visitor browser'));
        $flm->addOption('force',   __('Force a specific language'));
        $flm->optionColumns = 1;
        $fa->add($flm);

        $flf = $modules->get('InputfieldText');
        $flf->attr('name', 'locale_force');
        $flf->attr('value', isset($data['locale_force']) ? $data['locale_force'] : '');
        $flf->attr('placeholder', 'en');
        $flf->label = __('Forced Language Code');
        $flf->description = __('ISO language code to force for all visitors.');
        $flf->notes = __('Examples: en, fr, de, es, ru, zh. Full list: https://docs.crisp.chat/guides/chatbox-sdks/web-sdk/language/');
        $flf->columnWidth = 50;
        $flf->showIf = 'locale_mode=force';
        $fa->add($flf);

        $tApp->add($fa);
        $inputfields->add($tApp);

        // --- Tab: Session & Data ---
        $tData = new InputfieldWrapper();
        $tData->attr('id', 'crisp_tab_session_data');
        $tData->attr('title', __('Session & Data'));
        $tData->addClass('WireTab');

        // --- Session & User Data ---
        $fd = $modules->get('InputfieldFieldset');
        $fd->label = __('Session & User Data');
        $fd->description = __('Give Crisp operators more context about who they are talking to.');
        $fd->collapsed = Inputfield::collapsedNo;

        $fseg = $modules->get('InputfieldCheckbox');
        $fseg->attr('name', 'sync_segments');
        $fseg->attr('value', 1);
        $fseg->attr('checked', !empty($data['sync_segments']) ? 'checked' : '');
        $fseg->label = __('Tag session with user roles');
        $fseg->description = __('Sends the logged-in user\'s roles (or "guest") as Crisp session segments, so conversations can be filtered by role in the inbox.');
        $fseg->columnWidth = 50;
        $fd->add($fseg);

        $fpd = $modules->get('InputfieldCheckbox');
        $fpd->attr('name', 'sync_page_data');
        $fpd->attr('value', 1);
        $fpd->attr('checked', !empty($data['sync_page_data']) ? 'checked' : '');
        $fpd->label = __('Send current page as session data');
        $fpd->description = __('Sends the current page URL, title and template name as Crisp session data.');
        $fpd->columnWidth = 50;
        $fd->add($fpd);

        $fphone = $modules->get('InputfieldText');
        $fphone->attr('name', 'sync_phone_field');
        $fphone->attr('value', isset($data['sync_phone_field']) ? $data['sync_phone_field'] : '');
        $fphone->attr('placeholder', 'phone');
        $fphone->label = __('Phone Field Name');
        $fphone->description = __('Name of the ProcessWire user field holding a phone number. Leave blank to skip.');
        $fphone->columnWidth = 50;
        $fd->add($fphone);

        $fcompany = $modules->get('InputfieldText');
        $fcompany->attr('name', 'sync_company_field');
        $fcompany->attr('value', isset($data['sync_company_field']) ? $data['sync_company_field'] : '');
        $fcompany->attr('placeholder', 'company');
        $fcompany->label = __('Company Field Name');
        $fcompany->description = __('Name of the ProcessWire user field holding a company name. Leave blank to skip.');
        $fcompany->columnWidth = 50;
        $fd->add($fcompany);

        $tData->add($fd);
        $inputfields->add($tData);

        return $inputfields;
    }
}
<?php

/**
 * Crisp Live Chat for ProcessWire
 *
 * Integrates the Crisp live chat widget into ProcessWire sites.
 * Automatically syncs logged-in user data (email, name) with Crisp.
 *
 * @author  Maxim Alex <https://github.com/mxmsmnv>
 * @version 1.0.0
 * @license MIT
 */

class CrispLiveChat extends WireData implements Module, ConfigurableModule {

    /**
     * Module info required by ProcessWire
     */
    public static function getModuleInfo() {
        return [
            'title'    => 'Crisp Live Chat',
            'version'  => '1.0.0',
            'summary'  => 'Adds the Crisp live chat widget to your ProcessWire site. Supports automatic user identity sync and HMAC verification.',
            'author'   => 'Maxim Alex',
            'href'     => 'https://crisp.chat',
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
        ];
    }

    /**
     * Initialize module — hook into page render and handle Crisp OAuth callback
     */
    public function init() {
        $this->addHookAfter('Page::render', $this, 'injectCrispScript');
        $this->addHookAfter('ProcessModule::executeEdit', $this, 'handleCrispCallback');
    }

    /**
     * When Crisp redirects back after OAuth, it appends GET params:
     *   crisp_website_id=xxx and crisp_verify=yyy
     * Detect them and auto-save into module config, then redirect to clean URL.
     */
    public function handleCrispCallback(HookEvent $event) {
        $input   = $this->wire('input');
        $modules = $this->wire('modules');

        if ((string) $input->get('name') !== 'CrispLiveChat') return;

        $newId     = (string) $input->get->text('crisp_website_id');
        $newVerify = (string) $input->get->text('crisp_verify');

        if (!empty($newId)) {
            $configData = $modules->getModuleConfigData('CrispLiveChat');
            $configData['website_id'] = $this->wire('sanitizer')->text($newId);
            if (!empty($newVerify)) {
                $configData['website_verify'] = $this->wire('sanitizer')->text($newVerify);
            }
            $modules->saveModuleConfigData('CrispLiveChat', $configData);

            $adminUrl = rtrim($this->wire('config')->urls->httpAdmin, '/');
            $this->wire('session')->redirect($adminUrl . '/module/edit?name=CrispLiveChat&crisp_saved=1');
        }
    }

    /**
     * Inject the Crisp script into every front-end page
     */
    public function injectCrispScript(HookEvent $event) {
        $page = $event->object;

        // Only inject on front-end (non-admin) pages
        if ($page->template == 'admin') return;

        $website_id = $this->website_id;
        if (empty($website_id)) return;

        // Template filter
        $mode     = $this->templates_mode ?: 'all';
        $selected = is_array($this->templates_select) ? $this->templates_select : [];
        $tplName  = (string) $page->template->name;

        if ($mode === 'include' && count($selected) > 0) {
            if (!in_array($tplName, $selected)) return;
        } elseif ($mode === 'exclude' && count($selected) > 0) {
            if (in_array($tplName, $selected)) return;
        }

        // Logged-in only filter
        if ($this->logged_in_only && !$this->wire('user')->isLoggedin()) return;

        // Hidden roles filter
        $hiddenRoles = is_array($this->hidden_roles) ? $this->hidden_roles : [];
        if (count($hiddenRoles) > 0 && $this->wire('user')->isLoggedin()) {
            foreach ($hiddenRoles as $roleName) {
                if ($this->wire('user')->hasRole($roleName)) return;
            }
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
    window.CRISP_RUNTIME_CONFIG.color_theme = '{$safeColor}';";
        }

        $autoopen_js = $autoopen ? "
  \$crisp.push(['do', 'chat:open']);" : '';

        if ($locale !== null) {
            $locale_js = "if (!window.CRISP_RUNTIME_CONFIG.locale) { window.CRISP_RUNTIME_CONFIG.locale = '{$locale}'; }";
        } else {
            $locale_js = '';
        }

        $script = <<<HTML
<script>
  window.\$crisp = [];
  if (!window.CRISP_RUNTIME_CONFIG) {
    window.CRISP_RUNTIME_CONFIG = {};
  }
  {$locale_js}{$runtime_extras}
  CRISP_WEBSITE_ID = '{$website_id}';
  {$user_js}{$autoopen_js}
</script>
<script async src="https://client.crisp.chat/l.js"></script>
HTML;

        // Inject before </body>
        $event->return = str_replace('</body>', $script . '</body>', $event->return);
    }

    /**
     * Sync logged-in ProcessWire user data with Crisp
     */
    protected function syncUser() {
        $output = '';
        $user = $this->wire('user');

        if (!$user || !$user->isLoggedin()) return $output;

        $email  = $this->sanitizeJs($user->email);
        $name   = $this->sanitizeJs($user->name);
        $verify = $this->website_verify;

        if (!empty($email)) {
            if (!empty($verify)) {
                $hmac = hash_hmac('sha256', $email, $verify);
                $output .= "\$crisp.push(['set', 'user:email', ['{$email}', '{$hmac}']]);";
            } else {
                $output .= "\$crisp.push(['set', 'user:email', '{$email}']);";
            }
        }

        if (!empty($name)) {
            $output .= "\$crisp.push(['set', 'user:nickname', '{$name}']);";
        }

        return $output;
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
     * Escape a string for safe inline JS output
     */
    protected function sanitizeJs($value) {
        return addslashes(htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Module config fields (shown in Admin > Modules > Configure)
     */
    public static function getModuleConfigInputfields(array $data) {
        $data    = array_merge(self::getDefaultConfig(), $data);
        $modules = wire('modules');
        $config  = wire('config');

        $inputfields = new InputfieldWrapper();

        // --- Website ID ---
        /** @var InputfieldText $f */
        $f = $modules->get('InputfieldText');
        $f->attr('name', 'website_id');
        $f->attr('value', $data['website_id']);
        $f->label = __('Crisp Website ID');
        $f->description = __('Crisp app → Settings → Workspace Settings → Setup & Integrations → Website ID');
        $f->notes = __('Example: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
        $f->columnWidth = 100;
        $f->required = false;
        $inputfields->add($f);

        // --- HMAC Secret ---
        /** @var InputfieldText $f2 */
        $f2 = $modules->get('InputfieldText');
        $f2->attr('name', 'website_verify');
        $f2->attr('value', $data['website_verify']);
        $f2->label = __('User Verification Secret (optional)');
        $f2->description = __('Crisp app → Settings → Workspace Settings → Advanced configuration → Identity Verification. Requires a paid plan (Mini or higher). Leave blank if not used.');
        $f2->notes = __('Leave blank if you are not using Crisp user verification.');
        $f2->columnWidth = 100;
        $inputfields->add($f2);

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

        $inputfields->add($fs);

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

        $inputfields->add($fa);

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

        $inputfields->add($fv);

        // --- Status / Quick-link panel ---
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
            $inputfields->add($fm);
        } else {
            /** @var InputfieldMarkup $fm */
            $fm = $modules->get('InputfieldMarkup');
            $fm->label = __('Get started');

            // Build callback URL without double slashes
            // urls->admin already includes the full path like /narzan/
            $adminUrl    = rtrim(wire('config')->urls->httpAdmin, '/');
            $callbackUrl = $adminUrl . '/module/edit?name=CrispLiveChat';
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
                    ' . __('Paste your Website ID above, or') . '
                    <a href="' . $crispLink . '" target="_blank" style="color:#c53030;font-weight:bold;">
                        ' . __('connect via Crisp →') . '
                    </a>
                </div>';
            $inputfields->add($fm);
        }

        return $inputfields;
    }
}
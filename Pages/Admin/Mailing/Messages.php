<?php
/**
 * @file
 * lightningsdk\core\Pages\Mailing\Messages
 */

namespace lightningsdk\sitemanager\Pages\Admin\Mailing;

use lightningsdk\sitemanager\Model\Site;

/**
 * A page handler for editing bulk mailer messages.
 *
 * @package lightningsdk\core\Pages\Mailing
 */
class Messages extends \lightningsdk\core\Pages\Mailing\Messages {
    protected function initSettings() {
        parent::initSettings();
        $site = Site::getInstance();
        $this->preset['site_id'] = [
            'type' => 'hidden',
            'default' => $site->id,
            'force_default_new' => true,
        ];

        $this->accessControl['site_id'] = $site->id;
        $this->preset['template_id']['access_control'] = [
            'site_id' => ['IN', [0, $site->id]]
        ];
        $this->links['message_list']['access_control'] = [
            'site_id' => $site->id
        ];
    }
}

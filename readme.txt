=== Bangla Track Admin Server ===
Contributors: zahiduddin
Tags: license, management, bangla-track, admin
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

License management and monitoring server for Bangla Track Pro installations.

== Description ==

**Bangla Track Admin Server** is a centralized license management dashboard for managing Bangla Track Pro licenses.

= Features =

* **License Management** - Generate, edit, and revoke license keys
* **Activation Tracking** - Monitor all active Pro installations
* **REST API** - Endpoints for Pro plugins to validate licenses
* **Dashboard** - Overview statistics and recent activations

= API Endpoints =

* `POST /bt-server/v1/license/validate` - Validate a license key
* `POST /bt-server/v1/license/activate` - Activate license on a site
* `POST /bt-server/v1/license/deactivate` - Deactivate license from site
* `POST /bt-server/v1/license/status` - Check license status

== Installation ==

1. Upload the `bangla-track-admin-server` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to BT Server → Dashboard to start managing licenses

== Changelog ==

= 1.0.0 =
* Initial release
* License management dashboard
* REST API for license validation
* Activation tracking

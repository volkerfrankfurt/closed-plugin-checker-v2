Closed Plugin Checker - Version 2.0 Upgrade

Overview This update replaces the original Closed Plugin Checker (v1) with a completely rewritten, production-ready version (v2) that significantly improves reliability, user experience, and security.

Key Improvements

1. More Accurate Plugin Detection
- v1: Uses less reliable URL parsing (wp_parse_url) and string comparison to detect WordPress.org plugins
- v2: Leverages WordPress core's update_plugins transient with proper ID and package URL checking, mirroring how WP core actually determines plugin sources

3. Production-Ready API Handling
- v1. Deprecated Requests::request_multiple() library usage
- v2. Uses native wp_remote_get() with proper error handling, response code checking, and fallback logic

Enhanced User Experience / Features of version 2
- ✅ Admin Notices - Dismissible warning with plugin list
- ✅ Plugins Page Column - Visual status indicators
- ✅ Plugin Page Row Highlight - Red background for closed plugins
- ✅ Email Alerts - Automatic notifications on new closures
- ✅ Improved Caching - Optimized single transient + transition tracking

Email Alert System
- New proactive monitoring sends email to admin when a plugin's status changes to "closed".

Improved Site Health Integration
- Maintains the original Site Health test functionality
- Better formatted HTML output for closed plugins list
- Clearer messaging and actions

Better Caching Strategy
- Single consolidated cache (12 hours vs 24 hours for faster detection of changes)
- Tracks previous statuses to detect new closures for email alerts
- Reduced database queries

Security & Code Quality
- WordPress coding standards applied
- Security hardened with nonces and capability checks
- Proper text domain for translations
- Uses ABSPATH check for direct access prevention

Breaking Changes / Not Backward Compatible:
- Function names changed (prefixed with cpc_ to prevent conflicts)
- Transient key changed
- No longer uses the unused all_plugins filter hook

Seamless Transition:
- Site Health test remains functional with same hooks
- No manual migration needed - plugin handles its own data
- Installation Deactivate Version 1 (original) Delete original plugin files
- Upload Version 2 to /wp-content/plugins/ or use as a snippet. Activate "Closed Plugin Checker v. 2.0"

Testing Notes: 
The plugin includes commented test helpers (disabled by default). 
Uncomment these sections if you need to force refresh caches for testing.

Credits Original concept: Maciek Palmowski
Prior version: GitHub - palmiak/closed-plugin-checker

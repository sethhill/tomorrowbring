# API Timeout Fix for Career Transitions Report

## Problem

The career transitions report (and potentially other AI reports) was timing out after 60 seconds with the error:

```
cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received
```

Even though the system was configured with 600-second (10-minute) timeouts in multiple places.

## Root Cause

The Anthropic PHP SDK uses **PSR-18 HTTP client discovery** (`Psr18ClientDiscovery::find()`) to automatically find and use an HTTP client. This discovery mechanism bypasses Drupal's configured HTTP client and may discover a client with default timeout settings (60 seconds).

The timeout configuration in the following locations was not being respected:
- `config/sync/ai_provider_anthropic.settings.yml` (timeout: 600)
- `config/sync/ai_report_storage.settings.yml` (timeout: 600)
- `web/sites/default/settings.php` (http_client_config timeout: 600)

## Solution

Implemented a multi-layered approach to ensure the timeout is properly configured:

### 1. Event Subscriber for HTTP Client Discovery

Created `HttpClientDiscoverySubscriber` that runs early in each request to register a properly-configured Guzzle HTTP client with PSR-18 discovery. This ensures that when the Anthropic SDK discovers an HTTP client, it gets one with the correct timeout settings.

**File:** `web/modules/custom/ai_report_storage/src/EventSubscriber/HttpClientDiscoverySubscriber.php`

This subscriber:
- Reads timeout configuration from `ai_report_storage.settings` and `ai_provider_anthropic.settings`
- Creates a Guzzle client with proper timeout configuration
- Registers it with PSR-18 discovery using `HttpClientDiscovery::prependStrategy()`
- Sets both Guzzle-level and cURL-level timeout options

### 2. Enhanced settings.php Configuration

Added explicit cURL options to the HTTP client configuration:

```php
$settings['http_client_config']['curl'] = [
  CURLOPT_TIMEOUT => 600,
  CURLOPT_CONNECTTIMEOUT => 600,
  CURLOPT_TIMEOUT_MS => 600000,
  CURLOPT_CONNECTTIMEOUT_MS => 600000,
];
```

Also added:
```php
ini_set('curl.timeout', '600');
```

### 3. Updated AiReportServiceBase

Modified the `callAiApi` method to:
- Read the configured timeout from `ai_report_storage.settings`
- Pass it explicitly to the provider configuration
- Include it in the API call options
- Add comprehensive logging for debugging

**Changes in:** `web/modules/custom/ai_report_storage/src/AiReportServiceBase.php`

## Files Changed

1. **web/modules/custom/ai_report_storage/src/AiReportServiceBase.php**
   - Added configured timeout retrieval
   - Updated provider configuration to include timeout
   - Enhanced logging for timeout debugging

2. **web/modules/custom/ai_report_storage/src/EventSubscriber/HttpClientDiscoverySubscriber.php** (NEW)
   - Event subscriber that configures HTTP client discovery
   - Runs early in request lifecycle
   - Registers properly-configured HTTP client

3. **web/modules/custom/ai_report_storage/ai_report_storage.services.yml**
   - Registered the new event subscriber

4. **web/sites/default/settings.php**
   - Added explicit cURL timeout options
   - Added `curl.timeout` ini setting

5. **web/modules/custom/ai_report_storage/src/Http/AnthropicClientDecorator.php** (NEW)
   - HTTP client decorator (for potential future use)

## Required Actions

After deploying these changes:

1. **Clear Drupal cache:**
   ```bash
   drush cr
   ```

2. **Test the career transitions report generation:**
   ```bash
   drush queue:run generate_ai_report
   ```

3. **Monitor the debug log** (if enabled):
   ```bash
   tail -f /tmp/drupal_ai_debug.log
   ```

## Verification

To verify the fix is working:

1. Check the logs for "HTTP Client Discovery Configured" message
2. Monitor API calls in `/tmp/drupal_ai_debug.log` to see timeout values
3. Verify that API calls now complete successfully without 60-second timeout

## Configuration

The timeout is configured in:
- `config/sync/ai_report_storage.settings.yml` - timeout: 600
- `config/sync/ai_provider_anthropic.settings.yml` - timeout: '600'

To change the timeout:
1. Update the configuration values
2. Export configuration: `drush cex`
3. Clear cache: `drush cr`

## Technical Notes

- The Anthropic SDK default timeout is actually 600 seconds (see `vendor/anthropic-ai/sdk/src/RequestOptions.php`)
- The 60-second timeout was coming from a discovered HTTP client that didn't have proper configuration
- The event subscriber approach ensures that every request has access to a properly-configured client
- The timeout applies to both the HTTP request timeout and cURL-level timeouts for maximum compatibility

## Troubleshooting

If timeouts still occur:

1. Check `/tmp/drupal_ai_debug.log` for timing information
2. Verify the event subscriber is registered: `drush debug:event-subscriber`
3. Confirm the timeout value in logs matches configuration
4. Check PHP's `max_execution_time` setting (currently 600 seconds in settings.php)

## Future Improvements

Consider:
- Adding a UI setting for timeout configuration in the AI Report Storage settings form
- Implementing progressive timeout increases for retry attempts
- Adding Cloudflare or load balancer timeout checks if applicable



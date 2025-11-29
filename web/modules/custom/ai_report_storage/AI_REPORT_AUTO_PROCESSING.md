# AI Report Auto-Generation and Processing

## Overview

This document describes the automatic report generation and queue processing system for AI reports.

## Automatic Report Queueing

When a user completes all their assigned modules, the system automatically queues report generation for all enabled report types.

### Configuration

Configure automatic report queueing at: `/admin/config/client-dashboard/settings`

**Configuration Options:**
- **Enable automatic report processing**: Turn auto-queueing on/off
- **Delay before processing**: Optional delay in minutes (default: 0)
- **Report types**: Select which reports to automatically generate:
  - Role Impact Analysis
  - Career Transition Opportunities
  - Task Automation Recommendations
  - Industry Insights
  - Skills Analysis
  - Learning Resources
  - Breakthrough Strategies
  - Concerns Navigator

### Current Configuration

All report types are currently enabled for automatic queueing.

## Automatic Queue Processing

Reports are automatically processed in three ways:

### 1. Immediate Processing (Primary)

**When reports are queued, the first one starts processing immediately** in the same request. This ensures:
- Users don't have to wait for cron
- First report begins generating right away
- Immediate feedback that processing has started

The remaining reports will be processed by continued immediate processing or by cron.

### 2. Cron-based Processing (Backup/Catch-all)

The system processes the report queue automatically during Drupal cron runs:
- Processes up to 10 reports per cron run
- Runs on the standard Drupal cron schedule
- Logs all processing activity
- Acts as a safety net if immediate processing fails

**To trigger cron manually:**
```bash
drush cron
```

### 3. Manual Queue Processing (Drush Commands)

Two Drush commands are available for manual queue management:

#### Process the Queue

```bash
# Process all items in the queue
drush ai-reports:process-queue

# Or use the short alias
drush ai-queue

# Process only specific number of items
drush ai-reports:process-queue --limit=5
```

#### Check Queue Status

```bash
# View current queue status
drush ai-reports:queue-status

# Or use the short alias
drush ai-queue-status
```

## How It Works

### Workflow

1. **User completes final module** → Triggers `hook_webform_submission_update()`
2. **Auto-processor checks conditions**:
   - Is auto-processing enabled?
   - Are all modules completed?
3. **Queue reports**:
   - For each enabled report type
   - Check if report already exists or is pending
   - Check if user has minimum required data
   - Add to `generate_ai_report` queue
4. **Immediate processing**:
   - Process the first queue item immediately
   - This gives instant feedback to the user
5. **Continued processing** (via immediate or cron):
   - Remaining reports are processed
   - Cron acts as a backup/safety net

### Queue Processing Details

- **Queue Name**: `generate_ai_report`
- **Worker Plugin**: `GenerateAiReportWorker`
- **Processing Time**: ~30-60 seconds per report (varies by AI response time)
- **Immediate Processing**: 1 report processed immediately after queueing
- **Cron Limit**: 10 reports per cron run (prevents timeout)
- **Drush Limit**: Configurable via `--limit` option

## Testing

### Test Automatic Queueing and Immediate Processing

1. Ensure auto-processing is enabled in config
2. Complete the final module for a test user
3. **First report should start processing immediately**
4. Check the queue status:
   ```bash
   drush ai-queue-status
   ```
5. You should see 7 items remaining (one was processed immediately)

### Test Queue Processing

1. After queueing reports, check if first report started:
   ```bash
   drush watchdog:show --type=client_dashboard | head -20
   ```
2. You should see "Processing first queue item immediately"
3. Process the remaining items:
   ```bash
   drush ai-queue --limit=1
   ```
4. Check the output for success/failure
5. Check queue status again:
   ```bash
   drush ai-queue-status
   ```
6. Repeat until all reports are processed

### Test Cron Processing

1. Queue some reports (by completing modules)
2. Run cron:
   ```bash
   drush cron
   ```
3. Check logs:
   ```bash
   drush watchdog:show --type=ai_report_storage
   ```
4. Look for "Automatic queue processing complete" messages

## Monitoring

### Watchdog Logs

All queue processing is logged to the `ai_report_storage` channel:

```bash
# View recent logs
drush watchdog:show --type=ai_report_storage

# View logs in real-time
drush watchdog:tail --type=ai_report_storage
```

### Queue Status

Monitor the queue size and processing status:

```bash
drush ai-queue-status
```

## Troubleshooting

### Reports Not Queuing

Check:
1. Is auto-processing enabled? (`/admin/config/client-dashboard/settings`)
2. Are all modules completed for the user?
3. Are the report types enabled in config?
4. Does the user have minimum required data?

### Queue Not Processing

Check:
1. Is cron running? (`drush cron`)
2. Are there errors in the logs? (`drush watchdog:show --type=ai_report_storage`)
3. Try manual processing: `drush ai-queue`

### Processing Failures

If a report fails to generate:
1. Check the error in watchdog logs
2. The queue item is released back to the queue for retry
3. Manual intervention may be needed if errors persist

### Common Issues

1. **API Timeout**: AI service may be slow or unavailable
2. **Missing Data**: User hasn't completed required modules
3. **Configuration**: Auto-processing disabled or report types not enabled

## Performance Considerations

- Each report takes 30-60 seconds to generate
- **First report starts immediately** when modules are completed
- Subsequent reports are processed by cron (10 per run)
- For 8 report types, expect one report immediately, then ~5-8 minutes for remaining reports
- Reports are generated in the background (mostly non-blocking)
- Users see a "pending" status while reports are being generated
- The immediate processing approach minimizes perceived wait time

## Future Enhancements

Potential improvements to consider:
- Delayed queue processing (respect `auto_process_delay` setting)
- Priority queue for different report types
- Batch processing with progress tracking
- Email notification when all reports are complete
- Rate limiting for API calls


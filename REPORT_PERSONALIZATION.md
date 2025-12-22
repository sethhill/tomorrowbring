# AI Report Personalization System

## Overview

This document explains how user profile data from webform submissions is synthesized into personalized AI-generated reports across 9 different report types.

## Architecture

### Data Collection Layer

**Webform Modules** → **Report Services** → **AI Prompts** → **Personalized Reports**

Users complete various webform modules that capture different aspects of their AI readiness journey:

- **Current AI Usage** (m1_*) - Tools used, frequency, comfort level
- **Task Analysis** (m2_*) - Role, daily tasks, automation preferences
- **Skills Gap** (m5_*) - Current skills, desired skills, learning barriers
- **Confidence** (m7_*) - Anxiety, excitement, confidence levels, emotional state
- **Training Preferences** (m6_*) - Learning style rankings, motivators, barriers
- **Ethics** (m11_*) - Ethical importance, concerns
- **Change Management** (m9_*) - Readiness for change, support needs
- **Future Vision** (m14_*) - Career outlook, valuable skills, threat/opportunity view

### Report Generation Architecture

Each AI report service extends `AiReportServiceBase` and follows this pattern:

```
User Profile Data
    ↓
Required Webform Data (always needed)
    ↓
Optional Webform Data (enhances personalization)
    ↓
Prompt Building (defensive extraction)
    ↓
AI Generation (Anthropic API)
    ↓
Validation & Storage
    ↓
Cached Report
```

## Report Types and Data Sources

### 1. Learning Resources Report

**Service**: `AiLearningResourcesService`

**Required Data**:
- Task Analysis (role, tasks)
- Skills Gap (current skills, desired skills, learning barriers)

**Optional Data**:
- **Training Preferences** (CRITICAL) - Learning style rankings, format preferences, motivators, barriers
- **Confidence** - Anxiety/confidence levels for timeline calibration

**Personalization Examples**:
- User ranks "Video tutorials" + "Hands-on labs" highly → Only recommends platforms with video/lab content
- Barrier "too_busy" → Emphasizes micro-learning sessions under 30 minutes
- Motivator "certification" → Prioritizes certified programs
- Anxiety > 3 → Adjusts 30/90-day timelines to be more achievable

**Key Output**: Platform recommendations, practice projects, 30/90-day learning sprints

---

### 2. Concerns Navigator Report

**Service**: `AiConcernsNavigatorService`

**Required Data**:
- Ethics (ethical importance, concerns)
- Future Vision (career outlook, threat/opportunity scale)

**Optional Data**:
- **Confidence** (perfect fit) - Anxiety, excitement, confidence, emotional state, support needs

**Personalization Examples**:
- Describes "overwhelmed" → Validates concerns without dismissing, focuses on small wins
- Describes "energized" → Matches energy, accelerates timeline recommendations
- Support needed "permission" → Includes leadership buy-in strategies
- Support needed "job_security" → Emphasizes how AI adoption increases job security

**Key Output**: Personalized narrative, concern responses, trust-building strategies, ethical positioning

---

### 3. Breakthrough Strategies Report

**Service**: `AiBreakthroughStrategiesService`

**Required Data**:
- Change Management (readiness, barriers, support)

**Optional Data**:
- **Confidence** - Emotional state for fear-to-empowerment calibration

**Personalization Examples**:
- Anxiety > 3 OR describes "overwhelmed/resistant" → Heavy emphasis on fear_to_empowerment, validating tone
- Describes "energized" → Skips fear work, focuses on acceleration
- Support needed references → Specific mentions in overcoming_barriers section

**Key Output**: Breakthrough insights, overcoming barriers, fear-to-empowerment journey

---

### 4. Role Impact Report

**Service**: `AiRoleImpactService`

**Required Data**:
- Task Analysis (role, tasks)
- Skills Gap (current skills, desired skills)

**Optional Data**:
- Current AI Usage (existing AI tool usage)
- **Confidence** - Tone calibration for displacement risk narrative

**Personalization Examples**:
- Anxiety > 3 → Softens displacement risk language, emphasizes agency and control
- Confidence < 3 → Breaks evolution_path into smaller, achievable steps
- Describes "overwhelmed" → Focuses on immediate, low-risk actions in positioning_points
- Confidence_boosters section references actual anxiety/confidence levels

**Key Output**: Displacement risk analysis, role evolution path, AI-enhanced positioning, confidence boosters

---

### 5. Career Transitions Report

**Service**: `AiCareerTransitionsService`

**Required Data**:
- Task Analysis (role, tasks)
- Skills Gap (current skills, desired skills)
- User Profile (industry from member profile)

**Optional Data**:
- **Confidence** - Timeline and risk adjustment

**Personalization Examples**:
- Confidence < 3 → Recommends longer timelines (e.g., "12-18 months" vs "6-12 months")
- Anxiety > 3 → Includes "low-risk exploration" options (side projects, informational interviews) in first_steps
- Support needed "mentor" → Emphasizes mentorship opportunities in why_viable for each role
- Emotional state → Adjusts transition_readiness.summary tone

**Key Output**: Transition readiness score, viable career transitions, transferable skills, first steps

---

### 6. Summary Report

**Service**: `AiSummaryService`

**Required Data**:
- Current AI Usage
- Task Analysis
- Ethics
- Future Vision

**Optional Data**:
- **Confidence** - Emotional journey narrative
- **Training Preferences** - Path forward alignment

**Personalization Examples**:
- journey_recap.where_you_started references initial emotional state (e.g., "overwhelmed" → "You started feeling overwhelmed...")
- ai_enablement_message tone matches current state (anxious → reassuring, energized → celebratory)
- path_forward aligned with learning preferences and barriers
- Describes "overwhelmed" → Emphasizes transformation from overwhelmed to capable

**Key Output**: Journey recap, comprehensive summary across all reports, path forward, AI enablement message

---

### 7. Skills Analyzer Report

**Service**: `AiSkillsAnalysisService`

**Required Data**:
- Skills Gap
- Task Analysis

**Optional Data**:
- **Training Preferences** - Skill acquisition recommendations

**Personalization Examples**:
- Learning preferences provided → Tailors skill acquisition to preferred learning styles
- Barrier "too_busy" → Emphasizes micro-learning opportunities and quick wins in critical_gaps
- Adjusts acquisition_difficulty timing based on available learning time and format preferences

**Key Output**: Skill trajectory, skill synergies, AI augmentation opportunities, motivational insights

---

### 8. Task Recommendations Report

**Service**: `AiTaskRecommenderService`

**Required Data**:
- Task Analysis (specific tasks + help/keep preferences)
- Current AI Usage (AI comfort level)

**Optional Data**:
- **Confidence** - Automation eagerness calibration

**Personalization Examples**:
- Anxiety > 3 → Starts with low-risk automation suggestions, emphasizes AI-assisted vs. full automation
- Confidence < 3 → Recommends easier-to-implement tools first (difficulty: easy), builds confidence gradually
- Describes "energized" OR excitement > 4 → Suggests aggressive automation strategy with advanced workflows
- Automation_potential tone adjusted based on emotional state

**Key Output**: Tool recommendations, automatable tasks, workflow integration strategies

---

### 9. Industry Insights Report

**Service**: `AiIndustryInsightsService`

**Required Data**:
- User Profile (industry from member profile)
- Task Analysis (role)
- Future Vision (career outlook)

**Optional Data**:
- Change Management (for transformation readiness context)

**Key Output**: Industry-specific AI trends, role-specific impacts, competitive positioning

---

## Shared Helper Methods

Located in `AiReportServiceBase` (lines 1177-1396), these methods ensure consistent personalization across all reports:

### `buildEmotionalContext(array $confidence_data): array`

**Extracts from confidence module**:
- Anxiety (1-5 scale)
- Excitement (1-5 scale)
- Confidence (1-5 scale)
- Self-description (overwhelmed, energized, cautious_optimistic, resistant, skeptical)
- Support needed (permission, understand_why, job_security, mentor, etc.)
- Leadership support (1-5 scale)
- Company trust (1-5 scale)

**Returns**:
```php
[
  'section' => 'EMOTIONAL STATE:\nAnxiety: 4/5, Excitement: 2/5...',
  'tone_rules' => 'TONE CALIBRATION:\n- Use reassuring tone...',
  'state' => ['anxiety' => 4, 'excitement' => 2, ...]
]
```

**Tone Calibration Rules** (Moderate Approach):
- Anxiety > 3 AND confidence < 3 → Reassuring, step-by-step tone, smaller steps
- Describes "overwhelmed/resistant" → Validate concerns, small wins, reduce pressure
- Describes "energized" OR excitement > 4 → Match energy, accelerated timelines, stretch goals
- Describes "cautious_optimistic" → Balance optimism with risk mitigation
- Support needed "permission" → Leadership buy-in strategies
- Support needed "understand_why" → Business case evidence and ROI
- Support needed "job_security" → Emphasize how actions increase security
- Leadership support < 3 → Peer networks and grassroots approaches

---

### `buildLearningPreferencesContext(array $training_prefs_data): array`

**Extracts from training_preferences module**:
- Learning style rankings (Likert scale: 1=least preferred, 3=highly preferred)
  - Video tutorials, Hands-on labs, Live instructor-led, Peer learning, Documentation, Coaching, Microlearning, Trial/error, Case studies, Certification programs
- Training time preference (work_hours, personal_compensated, flexible)
- Format preference (daily_micro, weekly_hour, self_paced, etc.)
- Practice preference (real_work, sandbox, mix)
- Top motivators (certification, impact, advancement, curiosity, etc.)
- Barriers (too_busy, no_support, not_relevant, etc.)

**Returns**:
```php
[
  'section' => 'LEARNING PREFERENCES:\nTop Learning Styles: Video tutorials, Hands-on labs...',
  'top_styles' => ['Video tutorials', 'Hands-on labs', 'Peer learning circles'],
  'barriers' => ['too_busy', 'no_support'],
  'motivators' => ['certification', 'impact']
]
```

**Personalization Rules Generated**:
- Top styles identified → "Recommend platforms offering: Video tutorials, Hands-on labs..."
- Barrier "too_busy" → "Emphasize micro-learning sessions (<30 min)"
- Barrier "no_support" → "Include self-directed, low-permission options"
- Motivator "certification" → "Prioritize certified programs and credentials"
- Motivator "impact" → "Show immediate, visible impact on daily work"
- Practice preference "real_work" → "Learning-by-doing with real projects"
- Practice preference "sandbox" → "Safe practice environments without consequences"

---

### `parseTopLearningStyles(array $rankings): array`

**Logic**:
1. Filters learning styles with Likert score >= 3 (highly preferred)
2. Sorts by score (highest first)
3. Returns top 3 styles with human-readable labels

**Example**:
```php
Input: ['video' => 5, 'labs' => 4, 'documentation' => 2, 'peer' => 3]
Output: ['Video tutorials', 'Hands-on labs', 'Peer learning circles']
```

---

## Data Flow Examples

### Example 1: Anxious Beginner

**Profile Data**:
- Anxiety: 5, Excitement: 2, Confidence: 1
- Describes: "overwhelmed"
- Support needed: permission, understand_why, job_security
- Learning style: Video (5), Labs (2), Documentation (1)
- Barrier: too_busy
- Motivator: job_security

**Report Personalization**:

**Learning Resources**:
- Platforms: ONLY video-based (YouTube, Coursera, LinkedIn Learning)
- 30-day sprint: Micro-sessions (15-20 min each), reassuring tone
- Immediate actions: Low-commitment first steps
- Timeline: Extended to reduce pressure

**Concerns Navigator**:
- Tone: Deeply reassuring, validates their overwhelm
- Concern responses: "It's completely normal to feel overwhelmed..."
- Trust building: Includes permission-seeking conversation starters for manager
- Support strategies: Emphasizes job security through adaptation

**Task Recommendations**:
- Tool recommendations: Easy difficulty only
- Automation suggestions: AI-assisted vs. full automation
- Workflow integration: Simple, low-risk first

**Career Transitions**:
- Timeline: 12-18 months (vs. 6-12 for confident users)
- First steps: "Informational interviews", "LinkedIn research" (low-risk)
- Mentorship heavily emphasized

---

### Example 2: Energized Adopter

**Profile Data**:
- Anxiety: 1, Excitement: 5, Confidence: 5
- Describes: "energized"
- Support needed: none (already has support)
- Learning style: Trial/error (5), Labs (4), Peer (3)
- Motivator: curiosity, advancement

**Report Personalization**:

**Learning Resources**:
- Platforms: Hands-on (DataCamp, Codecademy, GitHub)
- 30-day sprint: Aggressive timeline, challenging projects
- Immediate actions: Jump into advanced tools immediately
- Stretch goals included alongside foundational

**Concerns Navigator**:
- Tone: Matches their energy and enthusiasm
- Concern responses: Minimal (they're ready)
- Trust building: Accelerated implementation strategies
- Focus: Maximizing opportunity, not managing fear

**Task Recommendations**:
- Tool recommendations: Advanced difficulty included
- Automation suggestions: Aggressive automation strategy
- Workflow integration: Multi-tool complex workflows

**Career Transitions**:
- Timeline: 3-6 months (accelerated)
- First steps: "Build portfolio project", "Apply to stretch roles"
- Emphasizes rapid growth path

---

### Example 3: Cautious Optimist

**Profile Data**:
- Anxiety: 3, Excitement: 4, Confidence: 3
- Describes: "cautious_optimistic"
- Support needed: mentor, time
- Learning style: Labs (5), Video (4), Certification (3)
- Motivator: certification, advancement
- Barrier: no clear time

**Report Personalization**:

**Learning Resources**:
- Platforms: Certification-focused (Coursera Plus, AWS Training, Google Certificates)
- 30-day sprint: Balanced pace, includes backup plans
- Immediate actions: Mix of safe bets and stretch options
- Certification path emphasized

**Concerns Navigator**:
- Tone: Balances optimism with practical risk mitigation
- Concern responses: Provides both "safe bet" and "stretch" options
- Trust building: Incremental confidence building
- Support strategies: Finding mentors in organization

**Breakthrough Strategies**:
- Fear-to-empowerment: Moderate (acknowledges caution, encourages action)
- Overcoming barriers: Time management strategies for learning
- Insights: Practical + aspirational balance

**Career Transitions**:
- Timeline: 6-12 months (standard)
- First steps: Mix of research and action
- Mentorship opportunities highlighted

---

## Optional Data Pattern

All services follow this backward-compatible pattern:

### Required Webforms (Always Needed)

```php
protected function getRequiredWebforms(): array {
  return ['task_analysis', 'skills_gap'];
}
```

If user hasn't completed these, report won't generate.

### Optional Webforms (Enhance Personalization)

```php
public function generateReport($uid = NULL, bool $force_regenerate = FALSE, bool $retry = TRUE) {
  // ... standard cache checks ...

  // Required webforms
  foreach ($this->getRequiredWebforms() as $webform_id) {
    $result = $this->getSubmissionData($webform_id, $uid);
    if ($result) {
      $submission_data[$webform_id] = $result;
      $submission_ids[] = $result['sid'];
    }
  }

  // Optional: confidence (defensive - works with or without)
  $confidence_result = $this->getSubmissionData('confidence', $uid);
  if ($confidence_result) {
    $submission_data['confidence'] = $confidence_result;
    $submission_ids[] = $confidence_result['sid'];
  }

  // ... generate report ...
}
```

### Defensive Prompt Building

```php
protected function buildPrompt(array $submission_data): string {
  // Required data extraction
  $task_data = $submission_data['task_analysis']['data'] ?? [];
  $role = $task_data['m2_q1_role_category'] ?? 'unknown';

  // Optional data extraction (always use ?? fallback)
  $confidence_data = $submission_data['confidence']['data'] ?? [];
  $emotional_context = $this->buildEmotionalContext($confidence_data);
  $emotional_section = $emotional_context['section']; // Empty string if no data
  $tone_rules = $emotional_context['tone_rules']; // Empty string if no data

  return <<<PROMPT
Role: {$role}

{$emotional_section}

{$tone_rules}

Generate report...
PROMPT;
}
```

**Key principle**: Empty sections are gracefully handled by AI, so prompts work with OR without optional data.

---

## Source Hash System

Reports automatically regenerate when new data becomes available:

### How It Works

1. **Hash Calculation**: MD5 hash of all submission data (required + optional)
2. **Storage**: Hash stored with report entity
3. **Change Detection**: On next generation, current hash compared to stored hash
4. **Auto-Regeneration**: If hash differs, report queued for regeneration

### User Journey Example

**Day 1**: User completes required webforms (task_analysis, skills_gap)
- Learning Resources report generates
- Source hash: `abc123` (based on 2 submissions)

**Day 3**: User completes confidence module
- Source hash changes to `def456` (now 3 submissions)
- Learning Resources marked for regeneration
- New report includes emotional tone calibration

**Day 5**: User completes training_preferences module
- Source hash changes to `ghi789` (now 4 submissions)
- Learning Resources marked for regeneration again
- New report now has BOTH tone calibration AND learning style matching

**Day 7**: User views report
- No changes to submissions
- Source hash still `ghi789`
- Cached report returned instantly

---

## Report Matrix

| Report Type | Required Webforms | Optional Webforms | Primary Personalization |
|------------|------------------|------------------|------------------------|
| Learning Resources | task_analysis, skills_gap | training_preferences, confidence | Learning style matching, timeline adjustment |
| Concerns Navigator | ethics, future_vision | confidence | Emotional tone calibration, support strategies |
| Breakthrough Strategies | change_management | confidence | Fear-to-empowerment calibration |
| Role Impact | task_analysis, skills_gap | current_ai_usage, confidence | Displacement risk tone, evolution pacing |
| Career Transitions | task_analysis, skills_gap | confidence | Timeline adjustment, risk tolerance |
| Summary | current_ai_usage, task_analysis, ethics, future_vision | confidence, training_preferences | Journey narrative, overall tone |
| Skills Analyzer | skills_gap, task_analysis | training_preferences | Skill acquisition recommendations |
| Task Recommender | task_analysis, current_ai_usage | confidence | Automation eagerness, tool difficulty |
| Industry Insights | user_profile, task_analysis, future_vision | change_management | Industry-specific insights |

---

## Key Design Principles

### 1. Backward Compatibility
- Reports work perfectly without optional data
- No user sees degraded experience for incomplete profile
- Gradual enhancement as more data provided

### 2. Defensive Programming
- All optional data extraction uses `?? []` or `?? null`
- Helper methods return empty strings when no data
- AI prompts gracefully handle empty sections

### 3. Moderate Tone Calibration
- Adjusts pacing, not personality
- Reassurance level varies (not patronizing)
- Step sizes adapt (not dumbed down)
- Energy matching (not over-the-top)

### 4. Data Utilization > 80%
- Every collected data point has purpose
- No "collect and ignore" fields
- Specific rules for each data element

### 5. Automatic Enhancement
- No manual "regenerate" buttons needed
- Source hash detects changes automatically
- User sees progressive improvement naturally

---

## Testing Archetypes

For QA validation, test with these 5 user profiles:

### Anxious Beginner
- Anxiety: 5, Excitement: 2, Confidence: 1
- Describes: overwhelmed
- Support: permission, understand_why, job_security
- Learning: video, too_busy barrier

**Expected**: Highly reassuring tone, micro-learning focus, extended timelines, low-risk first steps

### Cautious Optimist
- Anxiety: 3, Excitement: 4, Confidence: 3
- Describes: cautious_optimistic
- Support: mentor, time
- Learning: labs, certification motivator

**Expected**: Balanced tone, certification emphasis, safe+stretch options, mentorship highlighted

### Energized Adopter
- Anxiety: 1, Excitement: 5, Confidence: 5
- Describes: energized
- Support: none (already supported)
- Learning: trial/error, curiosity motivator

**Expected**: High energy tone, challenging projects, accelerated timelines, advanced tools

### Skeptical Professional
- Anxiety: 2, Excitement: 2, Confidence: 4
- Describes: skeptical
- Support: understand_why, success_stories
- Learning: documentation, no_support barrier

**Expected**: Evidence-based tone, business case emphasis, self-directed options, ROI focus

### Resistant Veteran
- Anxiety: 4, Excitement: 1, Confidence: 2
- Describes: resistant
- Support: job_security, voice_concerns
- Learning: multiple barriers (too_busy, not_relevant)

**Expected**: Validating tone, job security emphasis, very small steps, relevance demonstrated

---

## Future Enhancements

Potential areas for expansion:

1. **Change Management Integration**: Add change readiness scores to more reports
2. **Social Support Context**: Leverage m7_q6_discussed_with (who they've talked to about AI)
3. **Quality of Life Tracking**: Use m7_q7_quality_of_life_expectation in summary narratives
4. **Industry Cross-Pollination**: Share insights across users in same industry
5. **Longitudinal Tracking**: Show emotional state changes over time in summary
6. **Learning Style Evolution**: Track if preferences change as confidence grows

---

## API Integration Points

### Anthropic API Configuration

**Model**: Claude 3.5 Sonnet (streaming)
**Max tokens**: 16000
**Temperature**: 0.7 (balanced creativity/consistency)

**Prompt Structure**:
```
[Profile Context]
ROLE: {role}
CURRENT SKILLS: {skills}

[Optional Personalization]
{emotional_section}
{learning_prefs_section}
{tone_rules}

[Task Instructions]
Generate JSON report with...

[Enhanced Rules]
- If anxiety > 3: ...
- If learning style video: ...
```

### Cache Strategy

**L1 Cache**: Drupal static cache (request lifetime)
**L2 Cache**: Drupal cache API (configurable TTL)
**L3 Cache**: Entity storage (permanent until source hash changes)

**Cache Invalidation Triggers**:
- New webform submission (any module)
- Force regenerate flag
- Manual cache clear

---

## Validation & Quality Control

### JSON Validation
All reports validate required structure:
```php
protected function validateResponse(array $response): bool {
  $required_fields = ['field1', 'field2', ...];
  foreach ($required_fields as $field) {
    if (!isset($response[$field])) {
      $this->logger->error('Missing field: @field', ['@field' => $field]);
      return FALSE;
    }
  }
  return TRUE;
}
```

### Personalization Quality Checks
- Tone appropriateness: Manual review of archetypes
- Learning style accuracy: Platform recommendations match preferences
- Timeline adjustment: Anxious users get longer timelines
- Generic language ratio: < 20% template statements

### Success Metrics
- Validation success rate: 100% (maintain)
- Cache hit rate: > 70%
- Data utilization: > 80% of available optional fields
- Tone match accuracy: > 90% appropriate to emotional state

---

## Developer Guide

### Adding New Optional Data to Existing Report

1. **Modify generateReport()** to collect new optional webform:
```php
$new_data = $this->getSubmissionData('new_module', $uid);
if ($new_data) {
  $submission_data['new_module'] = $new_data;
  $submission_ids[] = $new_data['sid'];
}
```

2. **Update buildPrompt()** to extract and use data:
```php
$new_module_data = $submission_data['new_module']['data'] ?? [];
$extracted_value = $new_module_data['field_name'] ?? 'default';
```

3. **Add to prompt** with defensive empty handling:
```php
$new_section = '';
if (!empty($new_module_data)) {
  $new_section = "NEW DATA:\nValue: {$extracted_value}\n";
}

return <<<PROMPT
{$new_section}
PROMPT;
```

4. **Clear cache**: `ddev drush cr`

### Creating New Report Type

See existing services as templates. Key steps:
1. Extend `AiReportServiceBase`
2. Implement `getReportType()`, `getModuleName()`, `getRequiredWebforms()`
3. Implement `buildPrompt()` with defensive extraction
4. Implement `validateResponse()` for JSON structure
5. Use helper methods: `buildEmotionalContext()`, `buildLearningPreferencesContext()`

---

## Conclusion

This personalization system creates a living, evolving profile that deepens with each webform completion. Users experience progressively more personalized reports as they share more about themselves, without any degradation if they choose not to complete optional modules. The moderate tone calibration ensures emotional intelligence without feeling manipulative, and the learning style matching ensures practical, actionable recommendations aligned with how they actually prefer to learn.

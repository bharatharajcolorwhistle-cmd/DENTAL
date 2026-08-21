<?php
/**
 * Shared reminder form fields (patient note / add-income layout)
 */

$reminder_date = $form_data['reminder_date'] ?? ($_GET['date'] ?? dcmt_get_current_date());
$reminder_time = $form_data['reminder_time'] ?? date('H:i');
$assigned_user_id = (int) ($form_data['assigned_user_id'] ?? ($dcmt_current_user['dcmt_id'] ?? 0));
$selected_assignees = $form_data['assignee_ids'] ?? ($assigned_user_id > 0 ? [$assigned_user_id] : []);
$title = $form_data['title'] ?? '';
$description = $form_data['description'] ?? '';
$priority = $form_data['priority'] ?? 'medium';
$category = $form_data['category'] ?? '';
$recurrence_rule = dcmt_reminder_normalize_recurrence_rule($form_data);
$recurrence_type = $recurrence_rule['type'];
$recurrence_interval = $recurrence_rule['interval'];
$recurrence_weekdays = $recurrence_rule['weekdays'];
$recurrence_monthly_mode = $recurrence_rule['monthly_mode'];
$recurrence_end_mode = $recurrence_rule['end_mode'];
$recurrence_end_date = $recurrence_rule['end_date'];
$recurrence_count = $recurrence_rule['count'] > 0 ? $recurrence_rule['count'] : 10;
$categories = dcmt_reminder_get_categories($dcmt_pdo);
$is_edit = !empty($is_edit);
$recurrence_summary = dcmt_reminder_format_recurrence_summary($recurrence_rule, (string) $reminder_date);
$max_reminder_date = dcmt_reminder_max_allowed_date();
$min_reminder_date = dcmt_get_current_date();
$reminder_date_max_help = str_replace('{years}', (string) DCMT_REMINDER_MAX_YEARS_AHEAD, trans('reminder', 'reminder_date_max_help'));
?>
<div class="row">
    <div class="col-md-8">
        <div class="mb-3">
            <label for="title" class="form-label"><?php echo trans('reminder', 'title'); ?> <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="title" name="title" maxlength="255" required
                   value="<?php echo htmlspecialchars($title); ?>"
                   placeholder="<?php echo htmlspecialchars(trans('reminder', 'title_placeholder')); ?>">
        </div>
    </div>
    <div class="col-md-4">
        <div class="mb-3">
            <label for="priority" class="form-label"><?php echo trans('reminder', 'priority'); ?></label>
            <select class="form-select" id="priority" name="priority">
                <option value="low" <?php echo $priority === 'low' ? 'selected' : ''; ?>><?php echo trans('reminder', 'priority_low'); ?></option>
                <option value="medium" <?php echo $priority === 'medium' ? 'selected' : ''; ?>><?php echo trans('reminder', 'priority_medium'); ?></option>
                <option value="high" <?php echo $priority === 'high' ? 'selected' : ''; ?>><?php echo trans('reminder', 'priority_high'); ?></option>
            </select>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="mb-3">
            <label for="reminder_date" class="form-label"><?php echo trans('reminder', 'reminder_date'); ?> <span class="text-danger">*</span></label>
            <input type="date" class="form-control" id="reminder_date" name="reminder_date" required
                   min="<?php echo htmlspecialchars($min_reminder_date); ?>"
                   max="<?php echo htmlspecialchars($max_reminder_date); ?>"
                   value="<?php echo htmlspecialchars($reminder_date); ?>">
            <div class="form-text"><?php echo htmlspecialchars($reminder_date_max_help); ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="mb-3">
            <label for="reminder_time" class="form-label"><?php echo trans('reminder', 'reminder_time'); ?> <span class="text-danger">*</span></label>
            <input type="time" class="form-control" id="reminder_time" name="reminder_time" required
                   value="<?php echo htmlspecialchars(substr($reminder_time, 0, 5)); ?>">
            <div class="form-text"><?php echo trans('reminder', 'notify_at_help'); ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="mb-3">
            <label for="category" class="form-label"><?php echo trans('reminder', 'category'); ?></label>
            <input type="text" class="form-control" id="category" name="category" maxlength="100"
                   value="<?php echo htmlspecialchars($category); ?>"
                   placeholder="<?php echo htmlspecialchars(trans('reminder', 'category_placeholder')); ?>"
                   list="reminderCategoryList">
            <datalist id="reminderCategoryList">
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat); ?>">
                <?php endforeach; ?>
            </datalist>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="mb-3">
            <label for="assignee_ids" class="form-label"><?php echo trans('reminder', 'assigned_to'); ?> <span class="text-danger">*</span></label>
            <select class="form-select dcmt-filter-field" id="assignee_ids" name="assignee_ids[]" multiple required>
                <?php foreach ($assignable_users as $u): ?>
                    <option value="<?php echo (int) $u['dcmt_id']; ?>"
                        <?php echo in_array((int) $u['dcmt_id'], $selected_assignees, true) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($u['dcmt_full_name'] ?: $u['dcmt_username']); ?>
                        (<?php echo htmlspecialchars($u['dcmt_role'] ?? ''); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" id="assigned_user_id" name="assigned_user_id" value="<?php echo (int) ($selected_assignees[0] ?? $assigned_user_id); ?>">
        </div>
    </div>
    <div class="col-md-6">
        <div class="mb-3">
            <label for="recurrence_type" class="form-label"><?php echo trans('reminder', 'recurrence'); ?></label>
            <?php if ($is_edit): ?>
                <input type="hidden" name="recurrence_type" value="<?php echo htmlspecialchars($recurrence_type); ?>">
                <input type="hidden" name="recurrence_interval" value="<?php echo (int) $recurrence_interval; ?>">
                <input type="hidden" name="recurrence_monthly_mode" value="<?php echo htmlspecialchars($recurrence_monthly_mode); ?>">
                <input type="hidden" name="recurrence_end_mode" value="<?php echo htmlspecialchars($recurrence_end_mode); ?>">
                <input type="hidden" name="recurrence_end_date" value="<?php echo htmlspecialchars($recurrence_end_date); ?>">
                <input type="hidden" name="recurrence_count" value="<?php echo (int) $recurrence_count; ?>">
                <?php foreach ($recurrence_weekdays as $weekday): ?>
                    <input type="hidden" name="recurrence_weekdays[]" value="<?php echo (int) $weekday; ?>">
                <?php endforeach; ?>
                <div class="form-control-plaintext">
                    <?php echo htmlspecialchars($recurrence_summary); ?>
                    <small class="text-muted d-block"><?php echo trans('reminder', 'recurrence_locked_help'); ?></small>
                </div>
            <?php else: ?>
            <select class="form-select" id="recurrence_type" name="recurrence_type">
                <option value="none" <?php echo $recurrence_type === 'none' ? 'selected' : ''; ?>><?php echo trans('reminder', 'recurrence_none'); ?></option>
                <option value="daily" <?php echo $recurrence_type === 'daily' ? 'selected' : ''; ?>><?php echo trans('reminder', 'recurrence_daily'); ?></option>
                <option value="weekly" <?php echo $recurrence_type === 'weekly' ? 'selected' : ''; ?>><?php echo trans('reminder', 'recurrence_weekly'); ?></option>
                <option value="monthly" <?php echo $recurrence_type === 'monthly' ? 'selected' : ''; ?>><?php echo trans('reminder', 'recurrence_monthly'); ?></option>
                <option value="yearly" <?php echo $recurrence_type === 'yearly' ? 'selected' : ''; ?>><?php echo trans('reminder', 'recurrence_yearly'); ?></option>
            </select>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$is_edit): ?>
<div id="recurrenceOptions" class="border rounded-3 p-3 mb-3 bg-light" style="<?php echo $recurrence_type === 'none' ? 'display:none;' : ''; ?>">
    <div class="row g-3 align-items-end">
        <div class="col-md-4">
            <label for="recurrence_interval" class="form-label"><?php echo trans('reminder', 'recurrence_every'); ?></label>
            <div class="input-group">
                <input type="number" class="form-control" id="recurrence_interval" name="recurrence_interval"
                       min="1" max="<?php echo (int) DCMT_REMINDER_RECURRENCE_MAX_INTERVAL; ?>"
                       value="<?php echo (int) $recurrence_interval; ?>">
                <span class="input-group-text" id="recurrenceIntervalUnit"><?php echo trans('reminder', 'recurrence_unit_' . ($recurrence_type === 'none' ? 'daily' : $recurrence_type)); ?></span>
            </div>
        </div>
        <div class="col-md-8" id="recurrenceWeeklyGroup" style="<?php echo $recurrence_type === 'weekly' ? '' : 'display:none;'; ?>">
            <label class="form-label d-block"><?php echo trans('reminder', 'recurrence_repeat_on'); ?></label>
            <div class="d-flex flex-wrap gap-1">
                <?php for ($weekday = 0; $weekday <= 6; $weekday++): ?>
                    <input type="checkbox" class="btn-check js-recurrence-weekday" name="recurrence_weekdays[]"
                           id="recurrence_weekday_<?php echo $weekday; ?>" value="<?php echo $weekday; ?>"
                           <?php echo in_array($weekday, $recurrence_weekdays, true) ? 'checked' : ''; ?>>
                    <label class="btn btn-outline-secondary btn-sm" for="recurrence_weekday_<?php echo $weekday; ?>">
                        <?php echo trans('reminder', 'weekday_short_' . $weekday); ?>
                    </label>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <div class="mt-3" id="recurrenceMonthlyGroup" style="<?php echo $recurrence_type === 'monthly' ? '' : 'display:none;'; ?>">
        <div class="form-check">
            <input class="form-check-input" type="radio" name="recurrence_monthly_mode" id="recurrenceMonthlyDay"
                   value="day_of_month" <?php echo $recurrence_monthly_mode !== 'nth_weekday' ? 'checked' : ''; ?>>
            <label class="form-check-label" for="recurrenceMonthlyDay" id="recurrenceMonthlyDayLabel">
                <?php echo str_replace('{day}', '1', trans('reminder', 'recurrence_monthly_on_day')); ?>
            </label>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="radio" name="recurrence_monthly_mode" id="recurrenceMonthlyNth"
                   value="nth_weekday" <?php echo $recurrence_monthly_mode === 'nth_weekday' ? 'checked' : ''; ?>>
            <label class="form-check-label" for="recurrenceMonthlyNth" id="recurrenceMonthlyNthLabel">
                <?php echo trans('reminder', 'recurrence_monthly_on_nth'); ?>
            </label>
        </div>
    </div>

    <div class="mt-3">
        <label class="form-label d-block"><?php echo trans('reminder', 'recurrence_ends'); ?></label>
        <div class="row g-3 align-items-center">
            <div class="col-md-4">
                <div class="form-check">
                    <input class="form-check-input js-recurrence-end-mode" type="radio" name="recurrence_end_mode"
                           id="recurrenceEndOnDate" value="date" <?php echo $recurrence_end_mode === 'date' ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="recurrenceEndOnDate"><?php echo trans('reminder', 'recurrence_ends_on'); ?></label>
                </div>
            </div>
            <div class="col-md-4" id="recurrenceEndDateWrap" style="<?php echo $recurrence_end_mode === 'date' ? '' : 'display:none;'; ?>">
                <input type="date" class="form-control" id="recurrence_end_date" name="recurrence_end_date"
                       min="<?php echo htmlspecialchars($min_reminder_date); ?>"
                       max="<?php echo htmlspecialchars($max_reminder_date); ?>"
                       value="<?php echo htmlspecialchars($recurrence_end_date); ?>">
            </div>
        </div>
        <div class="row g-3 align-items-center mt-1">
            <div class="col-md-4">
                <div class="form-check">
                    <input class="form-check-input js-recurrence-end-mode" type="radio" name="recurrence_end_mode"
                           id="recurrenceEndAfter" value="count" <?php echo $recurrence_end_mode === 'count' ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="recurrenceEndAfter"><?php echo trans('reminder', 'recurrence_ends_after'); ?></label>
                </div>
            </div>
            <div class="col-md-4" id="recurrenceEndCountWrap" style="<?php echo $recurrence_end_mode === 'count' ? '' : 'display:none;'; ?>">
                <div class="input-group">
                    <input type="number" class="form-control" id="recurrence_count" name="recurrence_count"
                           min="2" max="<?php echo (int) DCMT_REMINDER_RECURRENCE_MAX_INSTANCES; ?>"
                           value="<?php echo (int) $recurrence_count; ?>">
                    <span class="input-group-text"><?php echo trans('reminder', 'recurrence_occurrences'); ?></span>
                </div>
            </div>
        </div>
        <div class="form-check mt-2">
            <input class="form-check-input js-recurrence-end-mode" type="radio" name="recurrence_end_mode"
                   id="recurrenceEndNever" value="never" <?php echo $recurrence_end_mode === 'never' ? 'checked' : ''; ?>>
            <label class="form-check-label" for="recurrenceEndNever"><?php echo trans('reminder', 'recurrence_ends_never'); ?></label>
        </div>
        <div class="form-text mb-0">
            <?php echo trans('reminder', 'recurrence_help'); ?>
            <?php echo str_replace(
                ['{max}', '{years}'],
                [(string) DCMT_REMINDER_RECURRENCE_MAX_INSTANCES, (string) DCMT_REMINDER_MAX_YEARS_AHEAD],
                trans('reminder', 'recurrence_never_help')
            ); ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="mb-3">
    <label for="description" class="form-label"><?php echo trans('reminder', 'description'); ?></label>
    <textarea class="form-control" id="description" name="description" rows="6"
              placeholder="<?php echo htmlspecialchars(trans('reminder', 'description_placeholder')); ?>"><?php echo htmlspecialchars($description); ?></textarea>
    <div class="form-text"><?php echo trans('reminder', 'description_help'); ?></div>
</div>

<?php if (!$is_edit): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const recurrenceType = document.getElementById('recurrence_type');
    const recurrenceOptions = document.getElementById('recurrenceOptions');
    const weeklyGroup = document.getElementById('recurrenceWeeklyGroup');
    const monthlyGroup = document.getElementById('recurrenceMonthlyGroup');
    const intervalUnit = document.getElementById('recurrenceIntervalUnit');
    const endDateWrap = document.getElementById('recurrenceEndDateWrap');
    const endCountWrap = document.getElementById('recurrenceEndCountWrap');
    const dateInput = document.getElementById('reminder_date');
    const weekdayChecks = document.querySelectorAll('.js-recurrence-weekday');
    const units = {
        daily: <?php echo json_encode(trans('reminder', 'recurrence_unit_daily')); ?>,
        weekly: <?php echo json_encode(trans('reminder', 'recurrence_unit_weekly')); ?>,
        monthly: <?php echo json_encode(trans('reminder', 'recurrence_unit_monthly')); ?>,
        yearly: <?php echo json_encode(trans('reminder', 'recurrence_unit_yearly')); ?>
    };
    const weekdayLong = [
        <?php echo json_encode(trans('reminder', 'weekday_long_0')); ?>,
        <?php echo json_encode(trans('reminder', 'weekday_long_1')); ?>,
        <?php echo json_encode(trans('reminder', 'weekday_long_2')); ?>,
        <?php echo json_encode(trans('reminder', 'weekday_long_3')); ?>,
        <?php echo json_encode(trans('reminder', 'weekday_long_4')); ?>,
        <?php echo json_encode(trans('reminder', 'weekday_long_5')); ?>,
        <?php echo json_encode(trans('reminder', 'weekday_long_6')); ?>
    ];
    const ordinals = {
        first: <?php echo json_encode(trans('reminder', 'recurrence_ordinal_first')); ?>,
        second: <?php echo json_encode(trans('reminder', 'recurrence_ordinal_second')); ?>,
        third: <?php echo json_encode(trans('reminder', 'recurrence_ordinal_third')); ?>,
        fourth: <?php echo json_encode(trans('reminder', 'recurrence_ordinal_fourth')); ?>,
        last: <?php echo json_encode(trans('reminder', 'recurrence_ordinal_last')); ?>
    };
    const monthlyDayTpl = <?php echo json_encode(trans('reminder', 'recurrence_monthly_on_day')); ?>;
    const monthlyNthTpl = <?php echo json_encode(trans('reminder', 'recurrence_monthly_on_nth')); ?>;

    function selectedWeekdays() {
        return Array.from(weekdayChecks).filter((el) => el.checked).map((el) => parseInt(el.value, 10));
    }

    function parseReminderDate() {
        const value = dateInput ? dateInput.value : '';
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
        const dt = new Date(value + 'T12:00:00');
        return isNaN(dt.getTime()) ? null : dt;
    }

    function updateMonthlyLabels() {
        const dayLabel = document.getElementById('recurrenceMonthlyDayLabel');
        const nthLabel = document.getElementById('recurrenceMonthlyNthLabel');
        const dt = parseReminderDate();
        if (!dt || !dayLabel || !nthLabel) return;
        const dayNum = dt.getDate();
        const weekday = dt.getDay();
        const lastDay = new Date(dt.getFullYear(), dt.getMonth() + 1, 0).getDate();
        const nth = Math.ceil(dayNum / 7);
        const isLast = (dayNum + 7) > lastDay;
        const nthWord = isLast ? ordinals.last : (ordinals[['first', 'second', 'third', 'fourth'][nth - 1]] || ordinals.last);
        dayLabel.textContent = monthlyDayTpl.replace('{day}', String(dayNum));
        nthLabel.textContent = monthlyNthTpl.replace('{nth}', nthWord).replace('{weekday}', weekdayLong[weekday] || '');
    }

    function ensureWeeklyDefault() {
        if (!recurrenceType || recurrenceType.value !== 'weekly') return;
        if (selectedWeekdays().length > 0) return;
        const dt = parseReminderDate();
        if (!dt) return;
        const match = document.getElementById('recurrence_weekday_' + dt.getDay());
        if (match) match.checked = true;
    }

    function selectedEndMode() {
        const checked = document.querySelector('input[name="recurrence_end_mode"]:checked');
        return checked ? checked.value : 'date';
    }

    function syncRecurrenceUi() {
        const type = recurrenceType ? recurrenceType.value : 'none';
        if (recurrenceOptions) recurrenceOptions.style.display = type === 'none' ? 'none' : '';
        if (weeklyGroup) weeklyGroup.style.display = type === 'weekly' ? '' : 'none';
        if (monthlyGroup) monthlyGroup.style.display = type === 'monthly' ? '' : 'none';
        if (intervalUnit) intervalUnit.textContent = units[type] || units.daily;
        const endMode = selectedEndMode();
        if (endDateWrap) endDateWrap.style.display = endMode === 'date' ? '' : 'none';
        if (endCountWrap) endCountWrap.style.display = endMode === 'count' ? '' : 'none';
        updateMonthlyLabels();
        ensureWeeklyDefault();
    }

    if (recurrenceType) recurrenceType.addEventListener('change', syncRecurrenceUi);
    if (dateInput) dateInput.addEventListener('change', syncRecurrenceUi);
    document.querySelectorAll('.js-recurrence-end-mode').forEach((el) => {
        el.addEventListener('change', syncRecurrenceUi);
    });
    const reminderForm = document.getElementById('dcmtReminderForm');
    if (reminderForm) {
        reminderForm.addEventListener('reset', function() {
            setTimeout(syncRecurrenceUi, 0);
        });
    }
    syncRecurrenceUi();
});
</script>
<?php endif; ?>

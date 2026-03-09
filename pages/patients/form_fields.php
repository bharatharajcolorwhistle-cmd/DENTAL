<?php
/**
 * Shared patient form fields
 * Requires $form_data in scope
 */
?>
<div class="mb-4">
    <h5 class="mb-3"><i class="fas fa-user me-2"></i><?php echo trans('patient', 'section_personal'); ?></h5>
    <div class="row">
        <div class="col-md-3">
            <div class="mb-3">
                <label for="first_name" class="form-label"><?php echo trans('patient', 'first_name'); ?> <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="first_name" name="first_name"
                       value="<?php echo htmlspecialchars($form_data['first_name'] ?? ''); ?>"
                       required maxlength="100" placeholder="<?php echo trans('patient', 'first_name_placeholder'); ?>">
            </div>
        </div>
        <div class="col-md-3">
            <div class="mb-3">
                <label for="fathers_last_name" class="form-label"><?php echo trans('patient', 'fathers_last_name'); ?></label>
                <input type="text" class="form-control" id="fathers_last_name" name="fathers_last_name"
                       value="<?php echo htmlspecialchars($form_data['fathers_last_name'] ?? ''); ?>"
                       maxlength="100" placeholder="<?php echo trans('patient', 'fathers_last_name_placeholder'); ?>">
            </div>
        </div>
        <div class="col-md-3">
            <div class="mb-3">
                <label for="mothers_last_name" class="form-label"><?php echo trans('patient', 'mothers_last_name'); ?></label>
                <input type="text" class="form-control" id="mothers_last_name" name="mothers_last_name"
                       value="<?php echo htmlspecialchars($form_data['mothers_last_name'] ?? ''); ?>"
                       maxlength="100" placeholder="<?php echo trans('patient', 'mothers_last_name_placeholder'); ?>">
            </div>
        </div>
        <div class="col-md-3">
            <div class="mb-3">
                <label for="gender" class="form-label"><?php echo trans('patient', 'gender'); ?></label>
                <select class="form-select" id="gender" name="gender">
                    <option value="male" <?php echo $form_data['gender'] === 'male' ? 'selected' : ''; ?>><?php echo trans('patient', 'male'); ?></option>
                    <option value="female" <?php echo $form_data['gender'] === 'female' ? 'selected' : ''; ?>><?php echo trans('patient', 'female'); ?></option>
                    <option value="other" <?php echo $form_data['gender'] === 'other' ? 'selected' : ''; ?>><?php echo trans('patient', 'other'); ?></option>
                </select>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-4">
            <div class="mb-3">
                <label for="date_of_birth" class="form-label"><?php echo trans('patient', 'date_of_birth'); ?></label>
                <div class="input-group">
                    <input type="text" class="form-control" id="date_of_birth" name="date_of_birth"
                           value="<?php echo htmlspecialchars($form_data['date_of_birth'] ?? ''); ?>"
                           placeholder="YYYY-MM-DD" autocomplete="off">
                    <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
                </div>
                <div class="form-text"
                     id="dob_age_helper"
                     data-default-text="<?php echo trans('patient', 'date_of_birth_help'); ?>"
                     data-years-suffix="<?php echo trans('patient', 'years_old_suffix'); ?>">
                    <?php echo trans('patient', 'date_of_birth_help'); ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="mb-3">
                <label for="height_cm" class="form-label"><?php echo trans('patient', 'height'); ?></label>
                <div class="input-group">
                    <input type="number" step="0.01" class="form-control" id="height_cm" name="height_cm"
                           value="<?php echo htmlspecialchars($form_data['height_cm']); ?>"
                           min="0" placeholder="<?php echo trans('patient', 'height_placeholder'); ?>">
                    <span class="input-group-text">cm</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="mb-3">
                <label for="weight_kg" class="form-label"><?php echo trans('patient', 'weight'); ?></label>
                <div class="input-group">
                    <input type="number" step="0.01" class="form-control" id="weight_kg" name="weight_kg"
                           value="<?php echo htmlspecialchars($form_data['weight_kg']); ?>"
                           min="0" placeholder="<?php echo trans('patient', 'weight_placeholder'); ?>">
                    <span class="input-group-text">kg</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize daterangepicker for Date of Birth
    $('#date_of_birth').daterangepicker({
        singleDatePicker: true,
        showDropdowns: true,
        autoUpdateInput: false,
        minYear: 1900,
        maxYear: parseInt(moment().format('YYYY'), 10),
        locale: {
            format: 'YYYY-MM-DD',
            cancelLabel: '<?php echo trans('common', 'clear'); ?>',
            applyLabel: '<?php echo trans('common', 'apply'); ?>'
        },
        opens: 'left'
    });

    $('#date_of_birth').on('apply.daterangepicker', function(ev, picker) {
        $(this).val(picker.startDate.format('YYYY-MM-DD'));
        // Trigger native change event for vanilla JS listeners (age calculation)
        this.dispatchEvent(new Event('change'));
    });

    $('#date_of_birth').on('cancel.daterangepicker', function(ev, picker) {
        $(this).val('');
        this.dispatchEvent(new Event('change'));
    });
    
    // Set initial value if exists
    <?php if (!empty($form_data['date_of_birth'])): ?>
    var initialDate = '<?php echo htmlspecialchars($form_data['date_of_birth']); ?>';
    $('#date_of_birth').data('daterangepicker').setStartDate(initialDate);
    $('#date_of_birth').data('daterangepicker').setEndDate(initialDate);
    <?php endif; ?>

    // Allow clicking the calendar icon to focus input (opens picker)
    $('#date_of_birth').closest('.input-group').find('.input-group-text').on('click', function() {
        $('#date_of_birth').focus();
    });
});
</script>

<div class="row dcmt-contact-section-wrap g-4 align-items-start">

    <!-- LEFT COLUMN : CONTACT -->
    <div class="col-md-6">
        <div class="mb-4 p-3 dcmt-section-contact">
            <h5 class="mb-3">
                <i class="fas fa-address-book me-2"></i>
                <?php echo trans('patient', 'section_contact'); ?>
            </h5>

            <!-- Email + Phone -->
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="email" class="form-label">
                            <?php echo trans('patient', 'email'); ?>
                        </label>
                        <input type="email"
                               class="form-control"
                               id="email"
                               name="email"
                               value="<?php echo htmlspecialchars($form_data['email']); ?>"
                               maxlength="150"
                               placeholder="<?php echo trans('patient', 'email_placeholder'); ?>">
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="phone" class="form-label">
                            <?php echo trans('patient', 'phone'); ?>
                            <span class="text-danger">*</span>
                        </label>

                        <?php
                            $raw_phone = isset($form_data['phone']) ? (string) $form_data['phone'] : '';
                            $display_phone = preg_replace('/^\+52\s*/', '', $raw_phone);
                        ?>

                        <div class="input-group">
                            <span class="input-group-text">+52</span>
                            <input type="text"
                                   class="form-control"
                                   id="phone"
                                   name="phone"
                                   value="<?php echo htmlspecialchars($display_phone); ?>"
                                   required
                                   maxlength="25"
                                   inputmode="numeric"
                                   placeholder="<?php echo trans('patient', 'phone_placeholder'); ?>">
                        </div>

                        <?php if (!empty($phone_error)): ?>
                            <div class="text-danger small mt-1">
                                <?php echo htmlspecialchars($phone_error); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Address -->
            <div class="mb-3">
                <label for="address" class="form-label">
                    <?php echo trans('patient', 'address'); ?>
                </label>
                <textarea class="form-control"
                          id="address"
                          name="address"
                          rows="2"
                          placeholder="<?php echo trans('patient', 'address_placeholder'); ?>"><?php echo htmlspecialchars($form_data['address']); ?></textarea>
            </div>
        </div>
    </div>


    <!-- RIGHT COLUMN : EMERGENCY -->
    <div class="col-md-6">
        <div class="mb-4 p-3 dcmt-section-contact">
            <h5 class="mb-3">
                <i class="fas fa-phone-alt me-2"></i>
                <?php echo trans('patient', 'section_emergency'); ?>
            </h5>

            <!-- Name + Relation -->
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="emergency_contact_name" class="form-label">
                            <?php echo trans('patient', 'emergency_contact_name'); ?>
                        </label>
                        <input type="text"
                               class="form-control"
                               id="emergency_contact_name"
                               name="emergency_contact_name"
                               value="<?php echo htmlspecialchars($form_data['emergency_contact_name']); ?>"
                               maxlength="150"
                               placeholder="<?php echo trans('patient', 'emergency_contact_name_placeholder'); ?>">
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="emergency_contact_relation" class="form-label">
                            <?php echo trans('patient', 'emergency_contact_relation'); ?>
                        </label>
                        <input type="text"
                               class="form-control"
                               id="emergency_contact_relation"
                               name="emergency_contact_relation"
                               value="<?php echo htmlspecialchars($form_data['emergency_contact_relation']); ?>"
                               maxlength="100"
                               placeholder="<?php echo trans('patient', 'emergency_contact_relation_placeholder'); ?>">
                    </div>
                </div>
            </div>

            <!-- Emergency Phone -->
            <div class="mb-3">
                <label for="emergency_contact_phone" class="form-label">
                    <?php echo trans('patient', 'emergency_contact_phone'); ?>
                </label>
                <input type="text"
                               class="form-control"
                               id="emergency_contact_phone"
                               name="emergency_contact_phone"
                               value="<?php echo htmlspecialchars($form_data['emergency_contact_phone']); ?>"
                               maxlength="25"
                               placeholder="<?php echo trans('patient', 'emergency_contact_phone_placeholder'); ?>">
            </div>
        </div>
    </div>

</div>

<div class="mb-4 mt-4">
    <h5 class="mb-3"><i class="fas fa-notes-medical me-2"></i><?php echo trans('patient', 'section_medical'); ?></h5>
    <div class="row">
        <div class="col-md-6">
            <div class="mb-3">
                <label for="medications" class="form-label"><?php echo trans('patient', 'medications'); ?></label>
                <textarea class="form-control" id="medications" name="medications" rows="2"
                          placeholder="<?php echo trans('patient', 'medications_placeholder'); ?>"><?php echo htmlspecialchars($form_data['medications']); ?></textarea>
            </div>
        </div>
        <div class="col-md-6">
            <div class="mb-3">
                <label for="allergies" class="form-label"><?php echo trans('patient', 'allergies'); ?></label>
                <textarea class="form-control" id="allergies" name="allergies" rows="2"
                          placeholder="<?php echo trans('patient', 'allergies_placeholder'); ?>"><?php echo htmlspecialchars($form_data['allergies']); ?></textarea>
            </div>
        </div>
    </div>
</div>

<div class="mb-4">
    <h5 class="mb-3"><i class="fas fa-sticky-note me-2"></i><?php echo trans('patient', 'section_other'); ?></h5>
    <div class="row">
        <div class="col-md-8">
            <div class="mb-3">
                <label for="notes" class="form-label"><?php echo trans('patient', 'notes'); ?></label>
                <textarea class="form-control" id="notes" name="notes" rows="6"
                          placeholder="<?php echo trans('patient', 'notes_placeholder'); ?>"><?php echo htmlspecialchars($form_data['notes']); ?></textarea>
            </div>
        </div>
        <div class="col-md-4">
            <div class="mb-3">
                <label for="status" class="form-label"><?php echo trans('patient', 'status'); ?> <span class="text-danger">*</span></label>
                <select class="form-select" id="status" name="status" required>
                    <option value="active" <?php echo $form_data['status'] === 'active' ? 'selected' : ''; ?>><?php echo trans('common', 'active'); ?></option>
                    <option value="inactive" <?php echo $form_data['status'] === 'inactive' ? 'selected' : ''; ?>><?php echo trans('common', 'inactive'); ?></option>
                </select>
            </div>
        </div>
    </div>
</div>

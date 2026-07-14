/**
 * Reusable FDI odontogram for patient forms.
 * One SVG tooth template, seven sections, dynamic rows via loops.
 */
(function (global) {
    'use strict';

    var NS = 'http://www.w3.org/2000/svg';
    // 13 teeth per side (8 permanent + 5 primary) to match clinic chart layout.
    var UPPER_LEFT_MAIN = [18, 17, 16, 15, 14, 13, 12, 11];
    var UPPER_LEFT_SECONDARY = [55, 54, 53, 52, 51];
    var UPPER_RIGHT_MAIN = [21, 22, 23, 24, 25, 26, 27, 28];
    var UPPER_RIGHT_SECONDARY = [61, 62, 63, 64, 65];

    var LOWER_LEFT_SECONDARY = [85, 84, 83, 82, 81];
    var LOWER_LEFT_MAIN = [48, 47, 46, 45, 44, 43, 42, 41];
    var LOWER_RIGHT_SECONDARY = [71, 72, 73, 74, 75];
    var LOWER_RIGHT_MAIN = [31, 32, 33, 34, 35, 36, 37, 38];

    var ALL_TEETH = []
        .concat(
            UPPER_LEFT_MAIN,
            UPPER_LEFT_SECONDARY,
            UPPER_RIGHT_MAIN,
            UPPER_RIGHT_SECONDARY,
            LOWER_LEFT_SECONDARY,
            LOWER_LEFT_MAIN,
            LOWER_RIGHT_SECONDARY,
            LOWER_RIGHT_MAIN
        )
        .map(function (n) { return String(n); });
    // 7 sections to match reference:
    // - top cap (top)
    // - ring split into 4 quadrants (left, right, root-left, root-right)
    // - bottom cap (bottom)
    // - center circle (center)
    //
    // Note: we reuse legacy keys root-left/root-right for ring quadrants to keep DB compatibility.
    var SECTION_ORDER = ['top', 'root-left', 'right', 'root-right', 'bottom', 'left', 'center'];
    var STATE_CYCLE = ['default', 'damaged', 'filling', 'missing', 'crown', 'implant'];
    var WHOLE_TOOTH_STATES = ['filling', 'crown'];
    var TOOTH_META_TREATMENTS = 'treatments';
    var SECTION_LABEL_KEYS = {
        top: 'sectionTop',
        bottom: 'sectionBottom',
        left: 'sectionLeft',
        right: 'sectionRight',
        center: 'sectionCenter',
        'root-left': 'sectionRootLeft',
        'root-right': 'sectionRootRight'
    };
    var TOOTH_STATE_OPTIONS = [
        { key: 'default', labelKey: 'stateDefault' },
        { key: 'damaged', labelKey: 'stateDamaged' },
        { key: 'filling', labelKey: 'stateFilling' },
        { key: 'missing', labelKey: 'stateMissing' },
        { key: 'crown', labelKey: 'stateCrown' },
        { key: 'implant', labelKey: 'stateImplant' }
    ];
    var QUADRANT_LABEL = { tl: 'Q1', tr: 'Q2', bl: 'Q3', br: 'Q4' };
    var ZONA_POSTERIOR_KEY = 'zonaPosterior';
    var ZONA_ANTERIOR_KEY = 'zonaAnterior';
    var CHART_KEYS = ['problem', 'solution'];

    DcmtOdontogram._activeInstance = null;
    DcmtOdontogram._modalInitialized = false;

    function isChartSlice(data) {
        if (!data || typeof data !== 'object') {
            return false;
        }
        if (data.problem || data.solution) {
            return false;
        }
        return !!(data.teeth || data.zonaPosterior || data.zonaAnterior);
    }

    function resolveChartSlice(data, chartKey) {
        if (!data || typeof data !== 'object') {
            return {};
        }
        // Per-chart JSON from PHP embed (already problem OR solution slice).
        if (isChartSlice(data)) {
            return data;
        }
        if (chartKey && (data.problem || data.solution)) {
            return data[chartKey] && typeof data[chartKey] === 'object' ? data[chartKey] : {};
        }
        // Legacy single-chart document: belongs to problem only.
        if (data.teeth || data.zonaPosterior || data.zonaAnterior) {
            return chartKey === 'solution' ? {} : data;
        }
        return {};
    }

    function documentPayloadIsEmpty(doc) {
        if (!doc || typeof doc !== 'object') {
            return true;
        }
        return CHART_KEYS.every(function (key) {
            var slice = doc[key];
            if (!slice || typeof slice !== 'object') {
                return true;
            }
            var emptyTeeth = !slice.teeth || Object.keys(slice.teeth).length === 0;
            var emptyZ = zonaSideIsEmpty(slice.zonaPosterior || {}) && zonaSideIsEmpty(slice.zonaAnterior || {});
            return emptyTeeth && emptyZ;
        });
    }

    function emptyZonaSide() {
        return { tl: [], tr: [], bl: [], br: [] };
    }

    function getZonaKeyForZoneType(zoneType) {
        return zoneType === 'anterior' ? ZONA_ANTERIOR_KEY : ZONA_POSTERIOR_KEY;
    }

    function zonaSideIsEmpty(side) {
        if (!side) {
            return true;
        }
        return ['tl', 'tr', 'bl', 'br'].every(function (q) {
            return !side[q] || !side[q].length;
        });
    }

    var QUADRANT_TEETH = {
        tl: [].concat(UPPER_LEFT_MAIN, UPPER_LEFT_SECONDARY).map(String),
        tr: [].concat(UPPER_RIGHT_MAIN, UPPER_RIGHT_SECONDARY).map(String),
        bl: [].concat(LOWER_LEFT_SECONDARY, LOWER_LEFT_MAIN).map(String),
        br: [].concat(LOWER_RIGHT_SECONDARY, LOWER_RIGHT_MAIN).map(String)
    };

    function getToothZoneType(tooth) {
        var d = parseInt(String(tooth).slice(-1), 10);
        if (d >= 1 && d <= 3) {
            return 'anterior';
        }
        return 'posterior';
    }

    function getToothQuadrant(tooth) {
        var t = String(tooth);
        var keys = Object.keys(QUADRANT_TEETH);
        for (var i = 0; i < keys.length; i++) {
            if (QUADRANT_TEETH[keys[i]].indexOf(t) >= 0) {
                return keys[i];
            }
        }
        return 'tl';
    }

    function defaultProblemStates(i18n) {
        return TOOTH_STATE_OPTIONS.map(function (opt) {
            return {
                key: opt.key,
                name: (i18n && i18n[opt.labelKey]) ? i18n[opt.labelKey] : opt.key,
                wholeTooth: WHOLE_TOOTH_STATES.indexOf(opt.key) >= 0
            };
        });
    }

    function normalizeProblemStates(list, i18n) {
        if (!Array.isArray(list) || !list.length) {
            return defaultProblemStates(i18n || {});
        }
        return list.map(function (item) {
            if (!item || !item.key) {
                return null;
            }
            return {
                key: String(item.key),
                name: item.name ? String(item.name) : String(item.key),
                wholeTooth: !!item.wholeTooth
            };
        }).filter(Boolean);
    }

    function isWholeToothState(stateKey, wholeToothKeys) {
        var keys = wholeToothKeys || WHOLE_TOOTH_STATES;
        return keys.indexOf(stateKey) >= 0;
    }

    function toothSectionsUniformState(toothData, stateKey) {
        if (!toothData) {
            return false;
        }
        var marked = SECTION_ORDER.filter(function (sec) {
            var s = toothData[sec];
            return s && s !== 'default';
        });
        if (marked.length === 0) {
            return false;
        }
        return marked.every(function (sec) {
            return toothData[sec] === stateKey;
        });
    }

    function toothIsFullWholeToothState(toothData, stateKey, wholeToothKeys) {
        if (!toothData || !isWholeToothState(stateKey, wholeToothKeys)) {
            return false;
        }
        return SECTION_ORDER.every(function (sec) {
            return toothData[sec] === stateKey;
        });
    }

    // Geometry tuned to match the reference odontogram icon.
    var CX = 50;
    var CY = 54;
    // Main ring (single ring split into 4 parts)
    // Tightened inner radius to remove the visible gap with center circle.
    var RI = 15;
    var RO = 29;
    // Outer caps (top/bottom) — same thickness as ring, placed OUTSIDE the ring.
    // Ring thickness = RO - RI. Cap band = same thickness, with a small gap from the ring.
    var CAP_GAP = 3;
    var RI_CAP = RO + CAP_GAP;
    var RO_CAP = RI_CAP + (RO - RI);

    function degToRad(d) {
        return (d * Math.PI) / 180;
    }

    function donutWedgeR(degStart, degEnd, ri, ro) {
        var s1 = degToRad(degStart);
        var s2 = degToRad(degEnd);
        var x1 = CX + ro * Math.cos(s1);
        var y1 = CY + ro * Math.sin(s1);
        var x2 = CX + ro * Math.cos(s2);
        var y2 = CY + ro * Math.sin(s2);
        var x3 = CX + ri * Math.cos(s2);
        var y3 = CY + ri * Math.sin(s2);
        var x4 = CX + ri * Math.cos(s1);
        var y4 = CY + ri * Math.sin(s1);
        var large = degEnd - degStart > 180 ? 1 : 0;
        return (
            'M ' + x1.toFixed(2) + ' ' + y1.toFixed(2) +
            ' A ' + ro + ' ' + ro + ' 0 ' + large + ' 1 ' + x2.toFixed(2) + ' ' + y2.toFixed(2) +
            ' L ' + x3.toFixed(2) + ' ' + y3.toFixed(2) +
            ' A ' + ri + ' ' + ri + ' 0 ' + large + ' 0 ' + x4.toFixed(2) + ' ' + y4.toFixed(2) + ' Z'
        );
    }

    function donutWedge(degStart, degEnd) {
        return donutWedgeR(degStart, degEnd, RI, RO);
    }

    // Slight overlaps remove hairline gaps between sections.
    // Ring is split into 4 quadrants by an X (diagonals).
    var SECTION_PATHS = {
        // Outer caps
        top: donutWedgeR(208, 332, RI_CAP, RO_CAP),
        bottom: donutWedgeR(28, 152, RI_CAP, RO_CAP),
        // Ring quadrants (4 parts)
        // Upper-left
        'root-left': donutWedgeR(133, 227, RI, RO),
        // Upper-right
        right: donutWedgeR(313, 407, RI, RO),
        // Lower-right
        'root-right': donutWedgeR(43, 137, RI, RO),
        // Lower-left
        left: donutWedgeR(223, 317, RI, RO),
        center: '' // circle added separately
    };

    function nextState(current) {
        var i = STATE_CYCLE.indexOf(current);
        if (i < 0) {
            i = 0;
        }
        return STATE_CYCLE[(i + 1) % STATE_CYCLE.length];
    }

    /**
     * @param {number} toothNumber
     * @returns {HTMLElement}
     */
    function createTooth(toothNumber) {
        var cell = document.createElement('div');
        cell.className = 'dcmt-tooth-cell';
        cell.dataset.tooth = String(toothNumber);
        cell.setAttribute('role', 'group');
        cell.setAttribute('aria-label', 'Tooth ' + toothNumber);

        var label = document.createElement('span');
        label.className = 'dcmt-tooth-num';
        label.textContent = String(toothNumber);

        var svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('viewBox', '0 0 100 118');
        svg.setAttribute('class', 'dcmt-tooth-svg');
        svg.setAttribute('aria-hidden', 'true');

        SECTION_ORDER.forEach(function (section) {
            if (section === 'center') {
                var c = document.createElementNS(NS, 'circle');
                c.setAttribute('cx', '50');
                c.setAttribute('cy', '54');
                // Match center radius to ring inner radius (removes empty gap).
                c.setAttribute('r', String(RI));
                c.setAttribute('class', 'dcmt-tooth-section');
                c.dataset.section = section;
                c.dataset.tooth = String(toothNumber);
                svg.appendChild(c);
            } else {
                var p = document.createElementNS(NS, 'path');
                p.setAttribute('d', SECTION_PATHS[section]);
                p.setAttribute('class', 'dcmt-tooth-section');
                p.dataset.section = section;
                p.dataset.tooth = String(toothNumber);
                svg.appendChild(p);
            }
        });

        var foot = document.createElement('div');
        foot.className = 'dcmt-tooth-footprint';
        foot.dataset.toothFoot = String(toothNumber);

        cell.appendChild(label);
        cell.appendChild(svg);
        cell.appendChild(foot);

        return cell;
    }

    function renderHalf(rowTopTeeth, rowBottomTeeth, sideClass) {
        var half = document.createElement('div');
        half.className = 'dcmt-arch-half ' + sideClass;

        var topRow = document.createElement('div');
        topRow.className = 'dcmt-half-row dcmt-half-row--top ' + (rowTopTeeth.length === 8 ? 'dcmt-half-row--eight' : 'dcmt-half-row--five');
        rowTopTeeth.forEach(function (n) {
            topRow.appendChild(createTooth(n));
        });

        var bottomRow = document.createElement('div');
        bottomRow.className = 'dcmt-half-row dcmt-half-row--bottom ' + (rowBottomTeeth.length === 8 ? 'dcmt-half-row--eight' : 'dcmt-half-row--five');
        rowBottomTeeth.forEach(function (n) {
            bottomRow.appendChild(createTooth(n));
        });

        half.appendChild(topRow);
        half.appendChild(bottomRow);
        return half;
    }

    function renderRow(container, leftTop, leftBottom, rightTop, rightBottom) {
        if (!container) {
            return;
        }
        container.innerHTML = '';
        var leftWrap = renderHalf(leftTop, leftBottom, 'dcmt-arch-half--distal');
        var rightWrap = renderHalf(rightTop, rightBottom, 'dcmt-arch-half--mesial');

        container.appendChild(leftWrap);
        var axis = document.createElement('div');
        axis.className = 'dcmt-midline-axis';
        axis.setAttribute('aria-hidden', 'true');
        container.appendChild(axis);
        container.appendChild(rightWrap);
    }

    var DEFAULT_TREATMENT_COLOR = '#0D6EFD';

    function dcmtOdHasSelect2() {
        return typeof global.jQuery !== 'undefined'
            && global.jQuery.fn
            && typeof global.jQuery.fn.select2 === 'function';
    }

    function dcmtOdGetJQuery(selectEl) {
        if (!selectEl || !dcmtOdHasSelect2()) {
            return null;
        }
        return global.jQuery(selectEl);
    }

    function dcmtOdSelect2Active(selectEl) {
        var $el = dcmtOdGetJQuery(selectEl);
        return !!($el && $el.hasClass('select2-hidden-accessible'));
    }

    function dcmtOdGetSelectValue(selectEl) {
        if (!selectEl) {
            return '';
        }
        return selectEl.value || '';
    }

    function dcmtOdSetSelectValue(selectEl, value) {
        if (!selectEl) {
            return;
        }
        var next = value == null ? '' : String(value);
        var $el = dcmtOdGetJQuery(selectEl);
        if ($el && $el.hasClass('select2-hidden-accessible')) {
            $el.val(next).trigger('change');
            return;
        }
        selectEl.value = next;
    }

    function dcmtOdSetSelectDisabled(selectEl, disabled) {
        if (!selectEl) {
            return;
        }
        selectEl.disabled = !!disabled;
        var $el = dcmtOdGetJQuery(selectEl);
        if ($el && $el.hasClass('select2-hidden-accessible')) {
            $el.prop('disabled', !!disabled).trigger('change.select2');
        }
    }

    function dcmtOdInitModalSelect2(selectEl, options) {
        var $el = dcmtOdGetJQuery(selectEl);
        if (!$el) {
            return;
        }
        if ($el.hasClass('select2-hidden-accessible')) {
            $el.select2('destroy');
        }
        var modalEl = document.getElementById('dcmtOdontogramToothModal');
        var cfg = {
            width: '100%',
            minimumResultsForSearch: 0,
            dropdownParent: modalEl ? global.jQuery(modalEl) : global.jQuery(document.body)
        };
        options = options || {};
        if (options.placeholder) {
            cfg.placeholder = options.placeholder;
            cfg.allowClear = options.allowClear !== false;
        } else if (options.allowClear === false) {
            cfg.allowClear = false;
        }
        $el.select2(cfg);
        $el.next('.select2-container').addClass('dcmt-odontogram-select2');
    }

    function dcmtOdReplaceSelectOptions(selectEl, optionNodes, selectedValue) {
        if (!selectEl || !optionNodes) {
            return;
        }
        var $el = dcmtOdGetJQuery(selectEl);
        if ($el && $el.hasClass('select2-hidden-accessible')) {
            $el.empty();
            optionNodes.forEach(function (node) {
                $el.append(node);
            });
            $el.val(selectedValue == null ? '' : String(selectedValue)).trigger('change');
            return;
        }
        selectEl.innerHTML = '';
        optionNodes.forEach(function (node) {
            selectEl.appendChild(node);
        });
        selectEl.value = selectedValue == null ? '' : String(selectedValue);
    }

    function DcmtOdontogram(root, options) {
        this.root = root;
        this.options = options || {};
        this.treatments = Array.isArray(options.treatments) ? options.treatments : [];
        this.problemStates = normalizeProblemStates(options.problemStates, options.i18n || {});
        this._wholeToothKeys = this.problemStates.filter(function (p) { return p.wholeTooth; }).map(function (p) { return p.key; });
        this._wholeToothTreatments = this.treatments.filter(function (t) { return !!t.wholeTooth; }).map(function (t) { return t.name; });
        this.stateColors = options.stateColors && typeof options.stateColors === 'object'
            ? options.stateColors
            : {};
        this.state = {
            teeth: {},
            zonaPosterior: emptyZonaSide(),
            zonaAnterior: emptyZonaSide()
        };
        this.hiddenInput = null;
        this.activeTooth = null;
        this.activeSection = null;
        this.modalEl = null;
        this.modalBs = null;
        this.modalTooth = null;
        this.modalDraftTreatments = [];
        this.pendingLegendSelection = null;
        this._onToothInteract = this._onToothInteract.bind(this);
        this._onFormSubmit = this._onFormSubmit.bind(this);
    }

    DcmtOdontogram.prototype._isProblemChart = function () {
        return this.chartKey === 'problem';
    };

    DcmtOdontogram.prototype._isSolutionChart = function () {
        return this.chartKey === 'solution';
    };

    DcmtOdontogram.prototype._setPendingLegendSelection = function (selection) {
        if (selection && this.pendingLegendSelection
            && this.pendingLegendSelection.type === selection.type
            && this.pendingLegendSelection.value === selection.value) {
            this.pendingLegendSelection = null;
        } else {
            this.pendingLegendSelection = selection || null;
        }
        this._renderLegendSelection();
    };

    DcmtOdontogram.prototype._renderLegendSelection = function () {
        var current = this.pendingLegendSelection;
        this.root.querySelectorAll('.dcmt-odontogram-legend-btn').forEach(function (btn) {
            var matches = false;
            if (current) {
                if (current.type === 'problem') {
                    matches = btn.getAttribute('data-problem-key') === current.value;
                } else if (current.type === 'solution') {
                    matches = btn.getAttribute('data-treatment-name') === current.value;
                }
            }
            btn.classList.toggle('is-active', matches);
            btn.setAttribute('aria-pressed', matches ? 'true' : 'false');
        });
    };

    DcmtOdontogram.prototype._bindLegendInteractions = function () {
        var self = this;
        this.root.querySelectorAll('.dcmt-odontogram-legend-btn[data-problem-key]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (self.readonly || !self._isProblemChart()) {
                    return;
                }
                self._setPendingLegendSelection({
                    type: 'problem',
                    value: btn.getAttribute('data-problem-key') || ''
                });
            });
        });
        this.root.querySelectorAll('.dcmt-odontogram-legend-btn[data-treatment-name]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (self.readonly || !self._isSolutionChart()) {
                    return;
                }
                self._setPendingLegendSelection({
                    type: 'solution',
                    value: btn.getAttribute('data-treatment-name') || ''
                });
            });
        });
    };

    DcmtOdontogram.prototype._getDisplayTeeth = function () {
        return this.state.teeth || {};
    };

    DcmtOdontogram.prototype._getDisplaySectionState = function (tooth, section) {
        var teeth = this._getDisplayTeeth();
        return (teeth[tooth] && teeth[tooth][section]) || 'default';
    };

    DcmtOdontogram.prototype._sanitizeProblemTeeth = function (teeth) {
        var out = JSON.parse(JSON.stringify(teeth || {}));
        Object.keys(out).forEach(function (tooth) {
            if (!out[tooth] || typeof out[tooth] !== 'object') {
                delete out[tooth];
                return;
            }
            delete out[tooth][TOOTH_META_TREATMENTS];
            var hasSection = SECTION_ORDER.some(function (sec) {
                return out[tooth][sec] && out[tooth][sec] !== 'default';
            });
            if (!hasSection) {
                delete out[tooth];
            }
        });
        return out;
    };

    DcmtOdontogram.prototype._emitChange = function () {
        this.syncHidden();
        if (typeof this.options.onChange === 'function') {
            this.options.onChange(this.getPayload());
        }
    };

    DcmtOdontogram.prototype.getPayload = function () {
        if (this._isProblemChart()) {
            return {
                teeth: this._sanitizeProblemTeeth(this.state.teeth),
                zonaPosterior: emptyZonaSide(),
                zonaAnterior: emptyZonaSide()
            };
        }
        if (this._isSolutionChart()) {
            return {
                teeth: {},
                zonaPosterior: JSON.parse(JSON.stringify(this.state.zonaPosterior)),
                zonaAnterior: JSON.parse(JSON.stringify(this.state.zonaAnterior))
            };
        }
        return {
            teeth: JSON.parse(JSON.stringify(this.state.teeth)),
            zonaPosterior: JSON.parse(JSON.stringify(this.state.zonaPosterior)),
            zonaAnterior: JSON.parse(JSON.stringify(this.state.zonaAnterior))
        };
    };

    DcmtOdontogram.prototype.syncHidden = function () {
        if (typeof this.options.onSync === 'function') {
            this.options.onSync();
            return;
        }
        if (!this.hiddenInput) {
            return;
        }
        var p = this.getPayload();
        var emptyTeeth = Object.keys(p.teeth).length === 0;
        var emptyZ = zonaSideIsEmpty(p.zonaPosterior) && zonaSideIsEmpty(p.zonaAnterior);
        this.hiddenInput.value = emptyTeeth && emptyZ ? '{}' : JSON.stringify(p);
    };

    DcmtOdontogram.prototype._sectionEl = function (tooth, section) {
        return this.root.querySelector(
            '.dcmt-tooth-section[data-tooth="' + tooth + '"][data-section="' + section + '"]'
        );
    };

    DcmtOdontogram.prototype._applySectionState = function (tooth, section, st) {
        var el = this._sectionEl(tooth, section);
        if (!el) {
            return;
        }
        el.dataset.state = st;
        el.setAttribute('data-state', st);
        this._applySectionColorStyles(el, st);
    };

    DcmtOdontogram.prototype._applySectionColorStyles = function (el, stateKey) {
        if (!el) {
            return;
        }
        el.removeAttribute('data-solution-color');
        var colors = this.stateColors[stateKey];
        if (!colors || !colors.fill) {
            el.style.fill = '';
            el.style.stroke = '';
            el.style.opacity = '';
            return;
        }
        el.style.fill = colors.fill;
        el.style.stroke = colors.stroke || colors.fill;
        el.style.opacity = this._isDimmedState(stateKey) ? '0.45' : '';
    };

    DcmtOdontogram.prototype._darkenHexColor = function (hex, amount) {
        var value = String(hex || '').replace('#', '');
        if (value.length !== 6) {
            return hex || DEFAULT_TREATMENT_COLOR;
        }
        var r = Math.max(0, parseInt(value.slice(0, 2), 16) - (amount || 45));
        var g = Math.max(0, parseInt(value.slice(2, 4), 16) - (amount || 45));
        var b = Math.max(0, parseInt(value.slice(4, 6), 16) - (amount || 45));
        return '#' + [r, g, b].map(function (n) {
            return n.toString(16).padStart(2, '0');
        }).join('');
    };

    DcmtOdontogram.prototype._applySectionTreatmentColor = function (el, fillColor) {
        if (!el) {
            return;
        }
        var fill = fillColor || DEFAULT_TREATMENT_COLOR;
        var stroke = this._darkenHexColor(fill);
        el.dataset.state = 'default';
        el.setAttribute('data-state', 'default');
        el.setAttribute('data-solution-color', fill);
        el.style.fill = fill;
        el.style.stroke = stroke;
        el.style.opacity = '';
    };

    DcmtOdontogram.prototype._getSolutionTreatmentForBlock = function (tooth, section) {
        if (!this._isSolutionChart() || !section) {
            return null;
        }
        var zq = this._getToothZoneQuadrant(tooth);
        var idx = this._findEntryIndex(zq.zoneKey, zq.quadrant, tooth, section);
        if (idx < 0) {
            idx = this._findEntryIndex(zq.zoneKey, zq.quadrant, tooth, null);
        }
        if (idx < 0) {
            return null;
        }
        var entry = this.state[zq.zoneKey][zq.quadrant][idx];
        if (!entry || !Array.isArray(entry.treatments) || !entry.treatments.length) {
            return null;
        }
        return entry.treatments[0];
    };

    DcmtOdontogram.prototype._applyStateColors = function () {
        var self = this;
        Object.keys(this.stateColors).forEach(function (key) {
            var colors = self.stateColors[key];
            if (!colors || !colors.fill) {
                return;
            }
            self.root.style.setProperty('--dcmt-od-state-' + key + '-fill', colors.fill);
            if (colors.stroke) {
                self.root.style.setProperty('--dcmt-od-state-' + key + '-stroke', colors.stroke);
            }
            self.root.querySelectorAll('.dcmt-odontogram-legend-swatch[data-legend="' + key + '"]').forEach(function (swatch) {
                swatch.style.background = colors.fill;
            });
            self.root.querySelectorAll('.dcmt-od-state-btn[data-legend="' + key + '"]').forEach(function (btn) {
                btn.style.background = colors.fill;
                if (colors.stroke) {
                    btn.style.borderColor = colors.stroke;
                }
            });
        });
        self._clearModalSelectOptionColors();
        ALL_TEETH.forEach(function (tooth) {
            SECTION_ORDER.forEach(function (sec) {
                var el = self._sectionEl(tooth, sec);
                if (!el) {
                    return;
                }
                var st = el.getAttribute('data-state') || 'default';
                self._applySectionColorStyles(el, st);
            });
        });
    };

    DcmtOdontogram.prototype._clearModalSelectOptionColors = function () {
        var modal = document.getElementById('dcmtOdontogramToothModal');
        if (!modal) {
            return;
        }
        modal.querySelectorAll('#dcmtOdModalCondition option, #dcmtOdModalTreatmentAdd option').forEach(function (opt) {
            opt.style.removeProperty('color');
            opt.style.removeProperty('background');
            opt.style.removeProperty('background-color');
        });
    };

    DcmtOdontogram.prototype._treatmentColor = function (name) {
        for (var i = 0; i < this.treatments.length; i++) {
            if (this.treatments[i].name === name && this.treatments[i].color) {
                return this.treatments[i].color;
            }
        }
        return DEFAULT_TREATMENT_COLOR;
    };

    DcmtOdontogram.prototype._toothHasChartSections = function (toothData) {
        if (!toothData) {
            return false;
        }
        return SECTION_ORDER.some(function (sec) {
            return toothData[sec] && toothData[sec] !== 'default';
        });
    };

    DcmtOdontogram.prototype._getToothZoneQuadrant = function (tooth) {
        return {
            zoneType: getToothZoneType(tooth),
            zoneKey: getZonaKeyForZoneType(getToothZoneType(tooth)),
            quadrant: getToothQuadrant(tooth)
        };
    };

    DcmtOdontogram.prototype._getQuadrantEl = function (zoneKey, quadrant) {
        return this.root.querySelector(
            '.dcmt-zona-quadrant[data-zone-key="' + zoneKey + '"][data-quadrant="' + quadrant + '"]'
        );
    };

    DcmtOdontogram.prototype._getEntries = function (zoneKey, quadrant) {
        if (!this.state[zoneKey][quadrant]) {
            this.state[zoneKey][quadrant] = [];
        }
        return this.state[zoneKey][quadrant];
    };

    DcmtOdontogram.prototype._findEntryIndex = function (zoneKey, quadrant, tooth, section) {
        var entries = this._getEntries(zoneKey, quadrant);
        var toothKey = String(tooth);
        var sectionKey = section ? String(section) : null;
        for (var i = 0; i < entries.length; i++) {
            if (String(entries[i].tooth) !== toothKey) {
                continue;
            }
            if (this._isSolutionChart() && sectionKey) {
                if ((entries[i].section || null) === sectionKey) {
                    return i;
                }
                continue;
            }
            if (!this._isSolutionChart() || !entries[i].section) {
                return i;
            }
        }
        return -1;
    };

    DcmtOdontogram.prototype._getEntryForTooth = function (tooth, section) {
        var zq = this._getToothZoneQuadrant(tooth);
        var lookupSection = this._isSolutionChart() ? (section || null) : null;
        var idx = this._findEntryIndex(zq.zoneKey, zq.quadrant, tooth, lookupSection);
        if (idx < 0) {
            return null;
        }
        return this.state[zq.zoneKey][zq.quadrant][idx];
    };

    DcmtOdontogram.prototype._ensureEntry = function (tooth, section) {
        var zq = this._getToothZoneQuadrant(tooth);
        var entries = this._getEntries(zq.zoneKey, zq.quadrant);
        var entrySection = this._isSolutionChart() ? (section || null) : null;
        var idx = this._findEntryIndex(zq.zoneKey, zq.quadrant, tooth, entrySection);
        if (idx >= 0) {
            return entries[idx];
        }
        var entry = {
            tooth: String(tooth),
            section: entrySection,
            condition: null,
            treatments: []
        };
        entries.push(entry);
        return entry;
    };

    DcmtOdontogram.prototype._getEntryConditionForTooth = function (tooth) {
        var entry = this._getEntryForTooth(tooth);
        if (entry && entry.condition) {
            return entry.condition;
        }
        return this._getToothDominantState(tooth);
    };

    DcmtOdontogram.prototype._normalizeZonaEntries = function () {
        var self = this;
        [ZONA_POSTERIOR_KEY, ZONA_ANTERIOR_KEY].forEach(function (zoneKey) {
            ['tl', 'tr', 'bl', 'br'].forEach(function (q) {
                self._getEntries(zoneKey, q).forEach(function (entry) {
                    entry.tooth = String(entry.tooth);
                    if (entry.section && SECTION_ORDER.indexOf(entry.section) < 0) {
                        entry.section = null;
                    }
                    if (!Array.isArray(entry.treatments)) {
                        entry.treatments = [];
                    }
                    if (entry.condition === 'default') {
                        entry.condition = null;
                    }
                });
            });
        });
    };

    DcmtOdontogram.prototype._syncEntryFromTooth = function (tooth) {
        if (this._isSolutionChart()) {
            return;
        }
        if (this._isProblemChart()) {
            this._removeEntryForTooth(tooth);
            return;
        }
        var existing = this._getEntryForTooth(tooth);
        var savedTreatments = existing && Array.isArray(existing.treatments)
            ? existing.treatments.slice()
            : [];
        var hasTr = savedTreatments.length > 0;
        var hasSections = this._toothHasChartSections(this.state.teeth[tooth]);
        var t = this.state.teeth[tooth];
        var cond = null;
        if (t) {
            var wi;
            for (wi = 0; wi < this._wholeToothKeys.length; wi++) {
                var wholeKey = this._wholeToothKeys[wi];
                if (toothIsFullWholeToothState(t, wholeKey, this._wholeToothKeys)) {
                    cond = wholeKey;
                    break;
                }
            }
            if (!cond) {
                cond = this._getToothDominantState(tooth);
            }
        }
        if (!hasSections && !hasTr) {
            this._removeEntryForTooth(tooth);
            return;
        }
        var entry = this._ensureEntry(tooth);
        entry.treatments = savedTreatments;
        entry.condition = cond || (existing && existing.condition) || entry.condition || null;
    };

    DcmtOdontogram.prototype._removeEntryForTooth = function (tooth, section) {
        var zq = this._getToothZoneQuadrant(tooth);
        var idx = this._findEntryIndex(
            zq.zoneKey,
            zq.quadrant,
            tooth,
            this._isSolutionChart() ? (section || null) : null
        );
        if (idx >= 0) {
            this.state[zq.zoneKey][zq.quadrant].splice(idx, 1);
        }
    };

    DcmtOdontogram.prototype._toothHasSolutionTreatments = function (tooth) {
        if (!this._isSolutionChart()) {
            return false;
        }
        var zq = this._getToothZoneQuadrant(tooth);
        return this._getEntries(zq.zoneKey, zq.quadrant).some(function (entry) {
            return String(entry.tooth) === String(tooth)
                && Array.isArray(entry.treatments)
                && entry.treatments.length > 0;
        });
    };

    DcmtOdontogram.prototype._pruneEntry = function (zoneKey, quadrant, idx) {
        var entry = this.state[zoneKey][quadrant][idx];
        if (!entry) {
            return;
        }
        var hasCond = entry.condition && entry.condition !== 'default';
        var hasTr = entry.treatments && entry.treatments.length > 0;
        if (!hasCond && !hasTr) {
            this.state[zoneKey][quadrant].splice(idx, 1);
        }
    };

    DcmtOdontogram.prototype._isWholeToothState = function (stateKey) {
        return isWholeToothState(stateKey, this._wholeToothKeys);
    };

    DcmtOdontogram.prototype._isWholeToothTreatment = function (treatmentName) {
        var name = String(treatmentName || '');
        if (!name) {
            return false;
        }
        return (this._wholeToothTreatments || []).indexOf(name) >= 0;
    };

    DcmtOdontogram.prototype._solutionTreatmentsNeedBlock = function (treatmentNames) {
        var self = this;
        var list = Array.isArray(treatmentNames) ? treatmentNames : [];
        if (!list.length) {
            return false;
        }
        return list.some(function (name) {
            return !self._isWholeToothTreatment(name);
        });
    };

    DcmtOdontogram.prototype._clearAllSolutionEntriesForTooth = function (tooth) {
        var zq = this._getToothZoneQuadrant(tooth);
        this.state[zq.zoneKey][zq.quadrant] = this._getEntries(zq.zoneKey, zq.quadrant).filter(function (entry) {
            return String(entry.tooth) !== String(tooth);
        });
    };

    DcmtOdontogram.prototype._applySolutionTreatmentsToTooth = function (tooth, treatments, section) {
        var list = Array.isArray(treatments) ? treatments.slice() : [];
        if (!list.length) {
            if (section) {
                this._removeEntryForTooth(tooth, section);
            } else {
                this._clearAllSolutionEntriesForTooth(tooth);
            }
            return;
        }
        var applyWhole = !this._solutionTreatmentsNeedBlock(list);
        if (applyWhole) {
            this._clearAllSolutionEntriesForTooth(tooth);
            var wholeEntry = this._ensureEntry(tooth, null);
            wholeEntry.treatments = list;
            wholeEntry.condition = null;
            wholeEntry.section = null;
            return;
        }
        if (!section) {
            return;
        }
        // Block-level solutions replace any whole-tooth entry for this tooth.
        this._removeEntryForTooth(tooth, null);
        var entry = this._ensureEntry(tooth, section);
        entry.treatments = list;
        entry.condition = null;
        entry.section = section;
    };

    DcmtOdontogram.prototype._isDimmedState = function (stateKey) {
        return stateKey === 'missing';
    };

    DcmtOdontogram.prototype._populateModalConditionOptions = function (selected) {
        if (!this._condSel) {
            return;
        }
        var optionNodes = [];
        this.problemStates.forEach(function (problem) {
            var o = document.createElement('option');
            o.value = problem.key;
            o.textContent = problem.name || problem.key;
            optionNodes.push(o);
        });
        dcmtOdReplaceSelectOptions(this._condSel, optionNodes, selected == null ? 'default' : String(selected));
    };

    DcmtOdontogram.prototype._stateLabel = function (stateKey) {
        for (var i = 0; i < this.problemStates.length; i++) {
            if (this.problemStates[i].key === stateKey) {
                return this.problemStates[i].name || stateKey;
            }
        }
        var i18n = this.options.i18n || {};
        var map = {
            default: 'stateDefault',
            damaged: 'stateDamaged',
            filling: 'stateFilling',
            missing: 'stateMissing',
            crown: 'stateCrown',
            implant: 'stateImplant'
        };
        return i18n[map[stateKey]] || stateKey;
    };

    DcmtOdontogram.prototype._sectionLabel = function (section) {
        var i18n = this.options.i18n || {};
        var key = SECTION_LABEL_KEYS[section];
        if (key && i18n[key]) {
            return i18n[key];
        }
        return section;
    };

    DcmtOdontogram.prototype._formatToothConditionsSummary = function (tooth) {
        var t = this.state.teeth[tooth];
        if (!t) {
            return '';
        }
        var self = this;
        var i18n = this.options.i18n || {};
        var wholeLbl = i18n.wholeTooth || 'entire tooth';
        var wi;
        for (wi = 0; wi < this._wholeToothKeys.length; wi++) {
            var wholeKey = this._wholeToothKeys[wi];
            if (toothIsFullWholeToothState(t, wholeKey, this._wholeToothKeys)) {
                return self._stateLabel(wholeKey) + ' (' + wholeLbl + ')';
            }
        }
        var parts = [];
        SECTION_ORDER.forEach(function (sec) {
            var st = t[sec];
            if (st && st !== 'default') {
                parts.push(self._sectionLabel(sec) + ': ' + self._stateLabel(st));
            }
        });
        return parts.join(' · ');
    };

    DcmtOdontogram.prototype._highlightActiveSection = function (tooth, section) {
        var self = this;
        this.root.querySelectorAll('.dcmt-tooth-section.is-active-section').forEach(function (el) {
            el.classList.remove('is-active-section');
        });
        if (!tooth || !section) {
            return;
        }
        var el = this._sectionEl(tooth, section);
        if (el) {
            el.classList.add('is-active-section');
        }
        this.root.querySelectorAll('.dcmt-tooth-cell.is-active').forEach(function (c) {
            c.classList.remove('is-active');
        });
        var cell = this.root.querySelector('.dcmt-tooth-cell[data-tooth="' + tooth + '"]');
        if (cell) {
            cell.classList.add('is-active');
        }
    };

    DcmtOdontogram.prototype._filterTreatmentsForEntry = function () {
        return this.treatments.slice();
    };

    DcmtOdontogram.prototype._updateModalLayout = function () {
        if (!this.modalEl) {
            return;
        }
        var problemFields = this.modalEl.querySelector('.dcmt-od-modal-problem-fields');
        var solutionFields = this.modalEl.querySelector('.dcmt-od-modal-solution-fields');
        var isProblem = this._isProblemChart();
        var isSolution = this._isSolutionChart();
        if (problemFields) {
            problemFields.hidden = !isProblem;
        }
        if (solutionFields) {
            solutionFields.hidden = !isSolution;
        }
    };

    DcmtOdontogram.prototype._migrateLoadedData = function () {
        var self = this;
        ['zonaPosterior', 'zonaAnterior'].forEach(function (zoneKey) {
            ['tl', 'tr', 'bl', 'br'].forEach(function (q) {
                var val = self.state[zoneKey][q];
                if (typeof val === 'string') {
                    self.state[zoneKey][q] = [];
                } else if (!Array.isArray(val)) {
                    self.state[zoneKey][q] = [];
                }
            });
        });

        if (!self._isProblemChart()) {
            ALL_TEETH.forEach(function (tooth) {
                var t = self.state.teeth[tooth];
                if (!t || !Array.isArray(t[TOOTH_META_TREATMENTS]) || !t[TOOTH_META_TREATMENTS].length) {
                    return;
                }
                var entry = self._ensureEntry(tooth);
                t[TOOTH_META_TREATMENTS].forEach(function (name) {
                    if (entry.treatments.indexOf(name) < 0) {
                        entry.treatments.push(name);
                    }
                });
                delete t[TOOTH_META_TREATMENTS];
                if (self._toothRecordEmpty(t)) {
                    delete self.state.teeth[tooth];
                }
            });
        } else {
            ALL_TEETH.forEach(function (tooth) {
                var t = self.state.teeth[tooth];
                if (!t) {
                    return;
                }
                delete t[TOOTH_META_TREATMENTS];
                if (self._toothRecordEmpty(t)) {
                    delete self.state.teeth[tooth];
                }
            });
        }
    };

    DcmtOdontogram.prototype._getToothDominantState = function (tooth) {
        var t = this.state.teeth[tooth];
        if (!t) {
            return null;
        }
        var found = null;
        var mismatch = false;
        SECTION_ORDER.forEach(function (sec) {
            var st = t[sec];
            if (!st || st === 'default') {
                return;
            }
            if (!found) {
                found = st;
            } else if (found !== st) {
                mismatch = true;
            }
        });
        if (!found) {
            return null;
        }
        if (mismatch) {
            return found;
        }
        return found;
    };

    DcmtOdontogram.prototype._toothRecordEmpty = function (toothData) {
        if (!toothData) {
            return true;
        }
        var hasTreatments = Array.isArray(toothData[TOOTH_META_TREATMENTS]) && toothData[TOOTH_META_TREATMENTS].length > 0;
        return !this._toothHasChartSections(toothData) && !hasTreatments;
    };

    DcmtOdontogram.prototype._refreshToothFootprint = function (tooth) {
        var foot = this.root.querySelector('.dcmt-tooth-footprint[data-tooth-foot="' + tooth + '"]');
        var cell = this.root.querySelector('.dcmt-tooth-cell[data-tooth="' + tooth + '"]');
        var t = this.state.teeth[tooth];
        var hasState = !this._isSolutionChart() && this._toothHasChartSections(t);
        var entry = this._getEntryForTooth(tooth);
        var hasTr = this._isSolutionChart() && this._toothHasSolutionTreatments(tooth);
        if (!this._isSolutionChart() && !this._isProblemChart()) {
            hasTr = entry && entry.treatments && entry.treatments.length > 0;
        }
        var hasRecord = hasState || hasTr;
        if (foot) {
            foot.classList.toggle('has-state', hasState);
            foot.classList.toggle('has-treatments', !!hasTr);
            foot.innerHTML = '';
            foot.removeAttribute('title');
        }
        if (cell) {
            cell.classList.toggle('dcmt-tooth-cell--has-record', hasRecord);
            var title = '';
            if (hasRecord) {
                if (this._isSolutionChart() && entry && entry.treatments && entry.treatments.length) {
                    title = entry.treatments.join(', ');
                } else {
                    title = this._formatToothConditionsSummary(tooth);
                }
            }
            cell.setAttribute('title', title);
        }
    };

    DcmtOdontogram.prototype._loadPayload = function (data) {
        var slice = resolveChartSlice(data, this.options.chartKey || '');
        this.state = {
            teeth: {},
            zonaPosterior: emptyZonaSide(),
            zonaAnterior: emptyZonaSide()
        };
        if (slice && typeof slice === 'object') {
            if (slice.teeth && typeof slice.teeth === 'object') {
                this.state.teeth = JSON.parse(JSON.stringify(slice.teeth));
            }
            if (slice.zonaPosterior && typeof slice.zonaPosterior === 'object') {
                this.state.zonaPosterior = JSON.parse(JSON.stringify(slice.zonaPosterior));
            }
            if (slice.zonaAnterior && typeof slice.zonaAnterior === 'object') {
                this.state.zonaAnterior = JSON.parse(JSON.stringify(slice.zonaAnterior));
            }
        }
        if (this._isProblemChart()) {
            this.state.zonaPosterior = emptyZonaSide();
            this.state.zonaAnterior = emptyZonaSide();
        }

        this._migrateLoadedData();

        if (this._isSolutionChart()) {
            this.state.teeth = {};
        }
        this._normalizeZonaEntries();
        if (this._isProblemChart()) {
            this._normalizeWholeToothStates();
        }
        if (this.readonly) {
            if (this._isProblemChart()) {
                this._syncViewQuadrantEntriesFromTeeth();
            }
        } else if (!this._isSolutionChart()) {
            ALL_TEETH.forEach(function (t) {
                if (this._toothHasChartSections(this.state.teeth[t]) || this._getEntryForTooth(t)) {
                    this._syncEntryFromTooth(t);
                }
            }, this);
        }
        if (!this._isProblemChart()) {
            this._syncTeethFromZonaEntries();
        }
        this._paintAll();
        this._renderAllQuadrants();
        this._emitChange();
    };

    DcmtOdontogram.prototype._normalizeWholeToothStates = function () {
        var self = this;
        ALL_TEETH.forEach(function (tooth) {
            var t = self.state.teeth[tooth];
            if (!t) {
                return;
            }
            self._wholeToothKeys.forEach(function (st) {
                if (!toothSectionsUniformState(t, st)) {
                    return;
                }
                SECTION_ORDER.forEach(function (sec) {
                    self._setSectionState(tooth, sec, st);
                });
            });
        });
    };

    DcmtOdontogram.prototype._applyConditionToTooth = function (tooth, condition, section) {
        var st = condition && condition !== 'default' ? condition : 'default';
        var self = this;
        if (this._isWholeToothState(st)) {
            SECTION_ORDER.forEach(function (sec) {
                self._setSectionState(tooth, sec, st);
            });
            return;
        }
        if (!section) {
            return;
        }
        this._setSectionState(tooth, section, st);
    };

    DcmtOdontogram.prototype._clearAllToothSections = function (tooth) {
        var self = this;
        SECTION_ORDER.forEach(function (sec) {
            self._setSectionState(tooth, sec, 'default');
        });
    };

    DcmtOdontogram.prototype._syncTeethFromZonaEntries = function () {
        if (this._isSolutionChart()) {
            return;
        }
        var self = this;
        [ZONA_POSTERIOR_KEY, ZONA_ANTERIOR_KEY].forEach(function (zoneKey) {
            ['tl', 'tr', 'bl', 'br'].forEach(function (q) {
                self._getEntries(zoneKey, q).forEach(function (entry) {
                    if (entry.condition && self._isWholeToothState(entry.condition)) {
                        self._applyConditionToTooth(entry.tooth, entry.condition, null);
                    }
                });
            });
        });
    };

    DcmtOdontogram.prototype._updateModalBlockHint = function () {
        var i18n = this.options.i18n || {};
        if (this._blockHint) {
            this._blockHint.hidden = true;
        }
        if (this._solutionBlockHint) {
            this._solutionBlockHint.hidden = true;
        }

        if (this._isSolutionChart() && this._solutionBlockHint) {
            var draftNeedsBlock = this._solutionTreatmentsNeedBlock(this.modalDraftTreatments);
            if (!draftNeedsBlock && this.modalDraftTreatments && this.modalDraftTreatments.length) {
                this._solutionBlockHint.hidden = false;
                this._solutionBlockHint.className = 'dcmt-od-modal-solution-block-hint small text-muted mb-3';
                this._solutionBlockHint.textContent = i18n.wholeTooth || 'Applies to entire tooth';
                return;
            }
            this._solutionBlockHint.hidden = false;
            if (this.activeSection) {
                this._solutionBlockHint.className = 'dcmt-od-modal-solution-block-hint small text-muted mb-3';
                this._solutionBlockHint.textContent = (i18n.modalBlockSelected || 'Block: %s').replace(
                    '%s',
                    this._sectionLabel(this.activeSection)
                );
            } else {
                this._solutionBlockHint.className = 'dcmt-od-modal-solution-block-hint small text-warning mb-3';
                this._solutionBlockHint.textContent = i18n.selectBlockFirst || 'Click a block on the tooth.';
            }
            return;
        }

        if (!this._isProblemChart() || !this._blockHint || !this._condSel) {
            return;
        }
        var cond = dcmtOdGetSelectValue(this._condSel);
        var needsBlock = cond && cond !== 'default' && !this._isWholeToothState(cond);
        if (!needsBlock) {
            return;
        }
        this._blockHint.hidden = false;
        if (this.activeSection) {
            this._blockHint.className = 'dcmt-od-modal-block-hint small text-muted mb-3';
            this._blockHint.textContent = (i18n.modalBlockSelected || 'Block: %s').replace(
                '%s',
                this._sectionLabel(this.activeSection)
            );
        } else {
            this._blockHint.className = 'dcmt-od-modal-block-hint small text-warning mb-3';
            this._blockHint.textContent = i18n.selectBlockFirst || 'Click a block on the tooth for this condition.';
        }
    };

    DcmtOdontogram.prototype._renderAllQuadrants = function () {
        if (!this.root.querySelector('.dcmt-odontogram-zonas')) {
            return;
        }
        var self = this;
        [ZONA_POSTERIOR_KEY, ZONA_ANTERIOR_KEY].forEach(function (zoneKey) {
            ['tl', 'tr', 'bl', 'br'].forEach(function (q) {
                self._renderQuadrant(zoneKey, q);
            });
        });
    };

    DcmtOdontogram.prototype._renderQuadrant = function (zoneKey, quadrant) {
        var el = this._getQuadrantEl(zoneKey, quadrant);
        if (!el) {
            return;
        }
        var i18n = this.options.i18n || {};
        var entries = this._getEntries(zoneKey, quadrant);
        var list = el.querySelector('.dcmt-zona-q-list');
        if (!list) {
            return;
        }
        list.innerHTML = '';
        var self = this;
        entries.forEach(function (entry) {
            var row = document.createElement('div');
            row.className = 'dcmt-zona-q-entry dcmt-zona-q-entry--static';
            row.setAttribute('role', 'listitem');
            row.dataset.tooth = entry.tooth;
            if (entry.section) {
                row.dataset.section = entry.section;
            }
            var cond = '';
            if (!self._isSolutionChart()) {
                cond = self._formatToothConditionsSummary(entry.tooth);
                if (!cond && entry.condition) {
                    cond = self._stateLabel(entry.condition);
                }
            }
            if (!cond && !self._isSolutionChart()) {
                cond = '—';
            }
            var trList = Array.isArray(entry.treatments) ? entry.treatments : [];
            var trTxt = trList.length ? trList.join(', ') : '';
            var trLabel = i18n.clinicalTreatments || 'Treatments';
            var trLine = '';
            if (trList.length) {
                trLine = '<span class="dcmt-zona-q-entry-tr"><span class="dcmt-zona-q-entry-tr-label">' + trLabel + ':</span> ';
                trList.forEach(function (trName, idx) {
                    if (idx > 0) {
                        trLine += ', ';
                    }
                    var trColor = self._treatmentColor(trName);
                    trLine += '<span class="dcmt-zona-treatment-chip-inline" style="background:' + trColor + ';">' + trName + '</span>';
                });
                trLine += '</span>';
            }
            var blockLine = '';
            if (self._isSolutionChart() && entry.section) {
                blockLine = ' <span class="dcmt-zona-q-entry-block text-muted">('
                    + self._sectionLabel(entry.section) + ')</span>';
            }
            row.innerHTML =
                '<span class="dcmt-zona-q-entry-tooth">' + (i18n.toothLabel || 'Tooth') + ' ' + entry.tooth + blockLine + '</span>' +
                (self._isSolutionChart() ? '' : '<span class="dcmt-zona-q-entry-cond">' + cond + '</span>') +
                trLine;
            list.appendChild(row);
        });
    };

    DcmtOdontogram.prototype._syncViewQuadrantEntriesFromTeeth = function () {
        var self = this;
        ALL_TEETH.forEach(function (tooth) {
            if (!self._toothHasChartSections(self.state.teeth[tooth])) {
                return;
            }
            self._ensureEntry(tooth);
        });
    };

    DcmtOdontogram.prototype._initModal = function () {
        this.modalEl = document.getElementById('dcmtOdontogramToothModal');
        if (!this.modalEl || this.readonly) {
            return;
        }
        if (DcmtOdontogram._modalInitialized) {
            return;
        }
        DcmtOdontogram._modalInitialized = true;

        var i18n = this.options.i18n || {};
        var titleTpl = i18n.modalTitle || 'Tooth %s';
        var titleEl = this.modalEl.querySelector('#dcmtOdontogramToothModalLabel');
        var zoneEl = this.modalEl.querySelector('.dcmt-od-modal-zone');
        var condSel = this.modalEl.querySelector('#dcmtOdModalCondition');
        var trAdd = this.modalEl.querySelector('#dcmtOdModalTreatmentAdd');
        var trAddBtn = this.modalEl.querySelector('#dcmtOdModalTreatmentAddBtn');
        var saveBtn = this.modalEl.querySelector('#dcmtOdModalSaveBtn');
        var clearBtn = this.modalEl.querySelector('#dcmtOdModalClearBtn');
        var noTr = this.modalEl.querySelector('.dcmt-od-modal-no-treatments');
        var saveLbl = this.modalEl.querySelector('.dcmt-od-modal-save-label');
        var cancelLbl = this.modalEl.querySelector('.dcmt-od-modal-cancel-label');
        var clearLbl = this.modalEl.querySelector('.dcmt-od-modal-clear-label');
        if (saveLbl) {
            saveLbl.textContent = i18n.modalSave || 'Save';
        }
        if (cancelLbl) {
            cancelLbl.textContent = i18n.modalCancel || 'Cancel';
        }
        if (clearLbl) {
            clearLbl.textContent = i18n.modalClear || 'Clear tooth';
        }
        if (noTr) {
            noTr.textContent = i18n.noTreatments || 'No treatments for this zone.';
        }
        if (condSel) {
            var onCondChange = function () {
                var active = DcmtOdontogram._activeInstance;
                if (!active) {
                    return;
                }
                active._updateModalBlockHint();
                active._populateModalTreatmentOptions();
            };
            condSel.addEventListener('change', onCondChange);
            dcmtOdInitModalSelect2(condSel, { allowClear: false });
        }
        if (trAdd) {
            dcmtOdInitModalSelect2(trAdd, {
                placeholder: i18n.chooseTreatment || 'Choose treatment…',
                allowClear: true
            });
        }
        if (trAddBtn && trAdd) {
            trAddBtn.addEventListener('click', function () {
                var active = DcmtOdontogram._activeInstance;
                if (!active) {
                    return;
                }
                var name = dcmtOdGetSelectValue(trAdd).trim();
                if (!name) {
                    return;
                }
                if (active.modalDraftTreatments.indexOf(name) < 0) {
                    active.modalDraftTreatments.push(name);
                }
                dcmtOdSetSelectValue(trAdd, '');
                active._renderModalChips();
                active._updateModalBlockHint();
            });
        }
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                var active = DcmtOdontogram._activeInstance;
                if (active) {
                    active._saveToothModal();
                }
            });
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                var active = DcmtOdontogram._activeInstance;
                if (!active) {
                    return;
                }
                var i18nActive = active.options.i18n || i18n;
                var resetMsg = i18nActive.confirmResetTooth || 'Remove condition and treatments for this tooth?';
                if (active._isProblemChart()) {
                    resetMsg = i18nActive.confirmResetToothProblem || resetMsg;
                } else if (active._isSolutionChart()) {
                    resetMsg = i18nActive.confirmResetToothSolution || resetMsg;
                }
                if (global.confirm(resetMsg)) {
                    active._clearToothData(active.modalTooth);
                    if (active.modalBs) {
                        active.modalBs.hide();
                    }
                }
            });
        }
        this.modalEl.querySelector('.dcmt-od-modal-treatment-chips').addEventListener('click', function (ev) {
            var active = DcmtOdontogram._activeInstance;
            if (!active) {
                return;
            }
            var rm = ev.target.closest('.dcmt-od-modal-chip-remove');
            if (!rm) {
                return;
            }
            var name = rm.dataset.treatment || '';
            var idx = active.modalDraftTreatments.indexOf(name);
            if (idx >= 0) {
                active.modalDraftTreatments.splice(idx, 1);
                active._renderModalChips();
                active._populateModalTreatmentOptions();
                active._updateModalBlockHint();
            }
        });
        if (typeof global.bootstrap !== 'undefined' && global.bootstrap.Modal) {
            DcmtOdontogram._sharedModalBs = new global.bootstrap.Modal(this.modalEl);
        }
        if (dcmtOdHasSelect2()) {
            global.jQuery(document).off('select2:open.dcmtOdModal').on('select2:open.dcmtOdModal', function () {
                var input = document.querySelector('#dcmtOdontogramToothModal .select2-container--open .select2-search__field');
                if (input) {
                    input.focus();
                }
            });
        }
        DcmtOdontogram._titleEl = titleEl;
        DcmtOdontogram._zoneEl = zoneEl;
        DcmtOdontogram._blockHint = this.modalEl.querySelector('.dcmt-od-modal-block-hint');
        DcmtOdontogram._solutionBlockHint = this.modalEl.querySelector('.dcmt-od-modal-solution-block-hint');
        DcmtOdontogram._condSel = condSel;
        DcmtOdontogram._trAdd = trAdd;
        DcmtOdontogram._noTr = noTr;
        DcmtOdontogram._titleTpl = titleTpl;
    };

    DcmtOdontogram.prototype._bindSharedModalRefs = function () {
        this.modalEl = document.getElementById('dcmtOdontogramToothModal');
        this.modalBs = DcmtOdontogram._sharedModalBs || null;
        this._titleEl = DcmtOdontogram._titleEl;
        this._zoneEl = DcmtOdontogram._zoneEl;
        this._blockHint = DcmtOdontogram._blockHint;
        this._solutionBlockHint = DcmtOdontogram._solutionBlockHint;
        this._condSel = DcmtOdontogram._condSel;
        this._trAdd = DcmtOdontogram._trAdd;
        this._noTr = DcmtOdontogram._noTr;
        this._titleTpl = DcmtOdontogram._titleTpl;
    };

    DcmtOdontogram.prototype._commitPendingModalTreatment = function () {
        if (!this._trAdd) {
            return;
        }
        var name = dcmtOdGetSelectValue(this._trAdd).trim();
        if (!name) {
            return;
        }
        if (this.modalDraftTreatments.indexOf(name) < 0) {
            this.modalDraftTreatments.push(name);
        }
        dcmtOdSetSelectValue(this._trAdd, '');
    };

    DcmtOdontogram.prototype._populateModalTreatmentOptions = function () {
        if (!this._trAdd || !this.modalTooth) {
            return;
        }
        var i18n = this.options.i18n || {};
        var opt0 = document.createElement('option');
        opt0.value = '';
        opt0.textContent = i18n.chooseTreatment || 'Choose treatment…';
        var optionNodes = [opt0];
        var inSelect = { '': true };
        var hasOpts = false;
        var allowTreatmentPick = this._isSolutionChart() ? !!this.activeSection : false;
        if (allowTreatmentPick) {
            this._filterTreatmentsForEntry().forEach(function (tr) {
                if (this.modalDraftTreatments.indexOf(tr.name) >= 0 || inSelect[tr.name]) {
                    return;
                }
                var o = document.createElement('option');
                o.value = tr.name;
                o.textContent = tr.name;
                optionNodes.push(o);
                inSelect[tr.name] = true;
                hasOpts = true;
            }, this);
        }
        this.modalDraftTreatments.forEach(function (name) {
            if (inSelect[name]) {
                return;
            }
            var o = document.createElement('option');
            o.value = name;
            o.textContent = name + ' ✓';
            optionNodes.push(o);
            inSelect[name] = true;
            hasOpts = true;
        }, this);
        dcmtOdReplaceSelectOptions(this._trAdd, optionNodes, '');
        dcmtOdSetSelectDisabled(this._trAdd, !allowTreatmentPick);
        if (this._noTr) {
            this._noTr.hidden = !(allowTreatmentPick && !hasOpts && this.treatments.length > 0);
        }
        this._clearModalSelectOptionColors();
    };

    DcmtOdontogram.prototype._renderModalChips = function () {
        var chips = this.modalEl && this.modalEl.querySelector('.dcmt-od-modal-treatment-chips');
        if (!chips) {
            return;
        }
        chips.innerHTML = '';
        var self = this;
        this.modalDraftTreatments.forEach(function (name) {
            var chip = document.createElement('span');
            chip.className = 'badge rounded-pill dcmt-zona-treatment-chip text-white';
            chip.style.backgroundColor = self._treatmentColor(name);
            chip.textContent = name;
            var rm = document.createElement('button');
            rm.type = 'button';
            rm.className = 'btn-close btn-close-white btn-sm ms-1 dcmt-od-modal-chip-remove';
            rm.setAttribute('aria-label', 'Remove');
            rm.dataset.treatment = name;
            chip.appendChild(rm);
            chips.appendChild(chip);
        });
        self._populateModalTreatmentOptions();
    };

    DcmtOdontogram.prototype._openToothModal = function (tooth, section) {
        DcmtOdontogram._activeInstance = this;
        if (!this._condSel && DcmtOdontogram._modalInitialized) {
            this._bindSharedModalRefs();
        }
        if (this.readonly || !this.modalEl) {
            return;
        }
        var self = this;
        this.modalTooth = String(tooth);
        this.activeTooth = this.modalTooth;
        this.activeSection = section || null;
        var entry = this._getEntryForTooth(tooth);
        var t = this.state.teeth[tooth];
        var cond = 'default';
        if (this.activeSection && t && t[this.activeSection]) {
            cond = t[this.activeSection];
        } else if (entry && entry.condition) {
            cond = entry.condition;
        } else if (t) {
            var wi;
            for (wi = 0; wi < this._wholeToothKeys.length; wi++) {
                var wholeKey = this._wholeToothKeys[wi];
                if (toothIsFullWholeToothState(t, wholeKey, this._wholeToothKeys)) {
                    cond = wholeKey;
                    break;
                }
            }
            if (cond === 'default') {
                var dominant = this._getToothDominantState(tooth);
                if (dominant) {
                    cond = dominant;
                }
            }
        }
        if (!this.activeSection && t) {
            SECTION_ORDER.some(function (sec) {
                if (t[sec] && t[sec] !== 'default') {
                    self.activeSection = sec;
                    return true;
                }
                return false;
            });
        }
        if (this.activeSection && t && t[this.activeSection] && t[this.activeSection] !== 'default') {
            cond = t[this.activeSection];
        } else if (entry && entry.condition) {
            cond = entry.condition;
        }
        if (this._isProblemChart()) {
            this.modalDraftTreatments = [];
        } else if (this._isSolutionChart()) {
            var solutionEntry = this._getEntryForTooth(tooth, this.activeSection)
                || this._getEntryForTooth(tooth, null);
            this.modalDraftTreatments = solutionEntry && Array.isArray(solutionEntry.treatments)
                ? solutionEntry.treatments.slice()
                : [];
        } else {
            this.modalDraftTreatments = entry && Array.isArray(entry.treatments)
                ? entry.treatments.slice()
                : [];
        }
        if (this._titleEl) {
            var title = (this._titleTpl || 'Tooth %s').replace('%s', tooth);
            this._titleEl.textContent = title;
        }
        if (this._zoneEl) {
            this._zoneEl.textContent = this._getZoneContextLabel(tooth);
        }
        this._updateModalLayout();
        this._populateModalConditionOptions(cond);
        this._updateModalBlockHint();
        this._renderModalChips();
        this._populateModalTreatmentOptions();
        this._highlightActiveSection(tooth, this.activeSection);
        if (this.modalBs) {
            this.modalBs.show();
        } else {
            this.modalEl.classList.add('show');
            this.modalEl.style.display = 'block';
        }
    };

    DcmtOdontogram.prototype._saveToothModal = function () {
        if (!this.modalTooth || this.readonly) {
            return;
        }
        var i18n = this.options.i18n || {};
        var tooth = this.modalTooth;
        var section = this.activeSection;

        if (this._isSolutionChart()) {
            this._commitPendingModalTreatment();
            var solutionTreatments = this.modalDraftTreatments.slice();
            var needsBlock = this._solutionTreatmentsNeedBlock(solutionTreatments);
            if (solutionTreatments.length > 0 && needsBlock && !section) {
                global.alert(i18n.selectBlockFirst || 'Click a block on the tooth for this treatment.');
                return;
            }
            if (solutionTreatments.length === 0) {
                if (section) {
                    this._removeEntryForTooth(tooth, section);
                } else {
                    this._clearAllSolutionEntriesForTooth(tooth);
                }
            } else {
                this._applySolutionTreatmentsToTooth(
                    tooth,
                    solutionTreatments,
                    needsBlock ? section : null
                );
            }
            this._refreshToothFootprint(tooth);
            this._paintAll();
            this._renderAllQuadrants();
            this._focusZoneForTooth(tooth);
            this._emitChange();
            if (this.modalBs) {
                this.modalBs.hide();
            }
            return;
        }

        if (this._isProblemChart()) {
            var problemCond = this._condSel ? dcmtOdGetSelectValue(this._condSel) : 'default';
            if (problemCond && problemCond !== 'default' && !this._isWholeToothState(problemCond) && !section) {
                global.alert(i18n.selectBlockFirst || 'Click a block on the tooth for this condition.');
                return;
            }
            if (!problemCond || problemCond === 'default') {
                if (section) {
                    this._applyConditionToTooth(tooth, 'default', section);
                } else {
                    this._clearAllToothSections(tooth);
                }
            } else {
                this._applyConditionToTooth(tooth, problemCond, section);
            }
            if (this._toothRecordEmpty(this.state.teeth[tooth])) {
                delete this.state.teeth[tooth];
            }
            this._removeEntryForTooth(tooth);
            this._refreshToothFootprint(tooth);
            this._emitChange();
            if (this.modalBs) {
                this.modalBs.hide();
            }
            return;
        }

        this._commitPendingModalTreatment();
        var cond = this._condSel ? dcmtOdGetSelectValue(this._condSel) : 'default';
        var treatments = this.modalDraftTreatments.slice();

        if (cond && cond !== 'default' && !this._isWholeToothState(cond) && !section) {
            global.alert(i18n.selectBlockFirst || 'Click a block on the tooth for this condition.');
            return;
        }

        if ((!cond || cond === 'default') && treatments.length === 0) {
            this._clearToothData(tooth);
            if (this.modalBs) {
                this.modalBs.hide();
            }
            return;
        }

        var entry = this._ensureEntry(tooth);
        entry.treatments = treatments.slice();

        if (!cond || cond === 'default') {
            entry.condition = null;
            if (section) {
                this._applyConditionToTooth(tooth, 'default', section);
            }
        } else if (this._isWholeToothState(cond)) {
            entry.condition = cond;
            this._applyConditionToTooth(tooth, cond, null);
        } else {
            entry.condition = cond;
            this._applyConditionToTooth(tooth, cond, section);
        }

        this._syncEntryFromTooth(tooth);
        this._refreshToothFootprint(tooth);
        this._renderAllQuadrants();
        this._focusZoneForTooth(tooth);
        this._emitChange();
        if (this.modalBs) {
            this.modalBs.hide();
        }
    };

    DcmtOdontogram.prototype._focusZoneForTooth = function (tooth) {
        if (!this.root.querySelector('.dcmt-odontogram-zonas')) {
            return;
        }
        var zq = this._getToothZoneQuadrant(tooth);
        this.root.querySelectorAll('.dcmt-zona-card').forEach(function (card) {
            card.classList.remove('dcmt-zona-card--focused');
        });
        this.root.querySelectorAll('.dcmt-zona-quadrant').forEach(function (qEl) {
            qEl.classList.remove('dcmt-zona-quadrant--active');
        });
        var zoneCard = this.root.querySelector(
            zq.zoneType === 'anterior' ? '.dcmt-zona-card--anterior' : '.dcmt-zona-card--posterior'
        );
        if (zoneCard) {
            zoneCard.classList.add('dcmt-zona-card--focused');
        }
        var el = this._getQuadrantEl(zq.zoneKey, zq.quadrant);
        if (el) {
            el.classList.add('dcmt-zona-quadrant--active');
        }
    };

    DcmtOdontogram.prototype._clearToothData = function (tooth) {
        if (!tooth) {
            return;
        }
        if (this._isSolutionChart()) {
            this._removeEntryForTooth(tooth, this.activeSection);
            this._paintAll();
        } else if (this._isProblemChart()) {
            this._clearAllToothSections(tooth);
            if (this.state.teeth[tooth] && this._toothRecordEmpty(this.state.teeth[tooth])) {
                delete this.state.teeth[tooth];
            }
        } else {
            this._removeEntryForTooth(tooth);
            this._clearAllToothSections(tooth);
        }
        this.activeSection = null;
        this._refreshToothFootprint(tooth);
        this._renderAllQuadrants();
        this._emitChange();
        this.modalDraftTreatments = [];
        if (this._condSel) {
            dcmtOdSetSelectValue(this._condSel, 'default');
        }
        this._updateModalBlockHint();
        this._renderModalChips();
    };

    DcmtOdontogram.prototype._clearProblemSelectionFromTooth = function (tooth, section) {
        if (section) {
            this._applyConditionToTooth(tooth, 'default', section);
        } else {
            this._clearAllToothSections(tooth);
        }
        if (this._toothRecordEmpty(this.state.teeth[tooth])) {
            delete this.state.teeth[tooth];
        }
        this._removeEntryForTooth(tooth);
        this._paintAll();
        this._refreshToothFootprint(tooth);
        this._emitChange();
    };

    DcmtOdontogram.prototype._clearSolutionSelectionFromTooth = function (tooth, section) {
        if (section) {
            this._removeEntryForTooth(tooth, section);
        } else {
            var zq = this._getToothZoneQuadrant(tooth);
            this.state[zq.zoneKey][zq.quadrant] = this._getEntries(zq.zoneKey, zq.quadrant).filter(function (entry) {
                return String(entry.tooth) !== String(tooth);
            });
        }
        this._refreshToothFootprint(tooth);
        this._paintAll();
        this._renderAllQuadrants();
        this._focusZoneForTooth(tooth);
        this._emitChange();
    };

    DcmtOdontogram.prototype._applyLegendSelectionToTooth = function (tooth, section) {
        if (!this.pendingLegendSelection) {
            return false;
        }
        var selected = this.pendingLegendSelection;
        if (selected.type === 'problem' && this._isProblemChart()) {
            if (!selected.value) {
                return false;
            }
            if (selected.value === '__clear__') {
                this.activeTooth = String(tooth);
                this.activeSection = section || null;
                this._clearProblemSelectionFromTooth(tooth, section || null);
                return true;
            }
            if (!this._isWholeToothState(selected.value) && !section) {
                return false;
            }
            this.activeTooth = String(tooth);
            this.activeSection = section || null;
            this._applyConditionToTooth(tooth, selected.value, section || null);
            if (this._toothRecordEmpty(this.state.teeth[tooth])) {
                delete this.state.teeth[tooth];
            }
            this._removeEntryForTooth(tooth);
            this._paintAll();
            this._refreshToothFootprint(tooth);
            this._emitChange();
            return true;
        }
        if (selected.type === 'solution' && this._isSolutionChart()) {
            if (selected.value === '__clear__') {
                this.activeTooth = String(tooth);
                this.activeSection = section || null;
                this._clearSolutionSelectionFromTooth(tooth, section);
                return true;
            }
            if (!selected.value) {
                return false;
            }
            var isWholeTreatment = this._isWholeToothTreatment(selected.value);
            if (!isWholeTreatment && !section) {
                return false;
            }
            this.activeTooth = String(tooth);
            this.activeSection = isWholeTreatment ? null : section;
            this._applySolutionTreatmentsToTooth(
                tooth,
                [selected.value],
                isWholeTreatment ? null : section
            );
            this._refreshToothFootprint(tooth);
            this._paintAll();
            this._renderAllQuadrants();
            this._focusZoneForTooth(tooth);
            this._emitChange();
            return true;
        }
        return false;
    };

    DcmtOdontogram.prototype._onToothInteract = function (ev) {
        if (this.readonly) {
            return;
        }
        var sectionEl = ev.target.closest('.dcmt-tooth-section');
        var cell = ev.target.closest('.dcmt-tooth-cell');
        if (!sectionEl && !cell) {
            return;
        }
        var tooth = (sectionEl || cell).dataset.tooth;
        if (!tooth) {
            return;
        }
        ev.preventDefault();
        ev.stopPropagation();
        var section = null;
        if (this._isProblemChart() || this._isSolutionChart()) {
            section = sectionEl ? sectionEl.dataset.section : null;
        }
        if (this._applyLegendSelectionToTooth(tooth, section)) {
            return;
        }
    };

    DcmtOdontogram.prototype._paintAll = function () {
        var self = this;
        ALL_TEETH.forEach(function (t) {
            SECTION_ORDER.forEach(function (sec) {
                var el = self._sectionEl(t, sec);
                if (!el) {
                    return;
                }
                if (self._isSolutionChart()) {
                    var treatmentName = self._getSolutionTreatmentForBlock(t, sec);
                    if (treatmentName) {
                        self._applySectionTreatmentColor(el, self._treatmentColor(treatmentName));
                        return;
                    }
                }
                var st = self._getDisplaySectionState(t, sec);
                self._applySectionState(t, sec, st);
            });
            self._refreshToothFootprint(t);
        });
        if (!this.readonly && this.activeTooth) {
            this._highlightActiveSection(this.activeTooth, this.activeSection);
        }
    };

    DcmtOdontogram.prototype._getZoneContextLabel = function (tooth) {
        var zoneType = getToothZoneType(tooth);
        var quadrant = getToothQuadrant(tooth);
        var qLabel = QUADRANT_LABEL[quadrant] || quadrant;
        var i18n = this.options.i18n || {};
        var zoneName = zoneType === 'anterior'
            ? (i18n.zonaAnterior || 'Anterior zone')
            : (i18n.zonaPosterior || 'Posterior zone');
        return zoneName + ' ' + qLabel;
    };

    DcmtOdontogram.prototype._setSectionState = function (tooth, section, st) {
        if (!this.state.teeth[tooth]) {
            this.state.teeth[tooth] = {};
        }
        if (st === 'default') {
            delete this.state.teeth[tooth][section];
        } else {
            this.state.teeth[tooth][section] = st;
        }
        if (this._toothRecordEmpty(this.state.teeth[tooth])) {
            delete this.state.teeth[tooth];
        }
        this._applySectionState(tooth, section, st);
    };

    DcmtOdontogram.prototype.reset = function () {
        this.state = {
            teeth: {},
            zonaPosterior: emptyZonaSide(),
            zonaAnterior: emptyZonaSide()
        };
        this.activeTooth = null;
        this.activeSection = null;
        this.modalTooth = null;
        this.modalDraftTreatments = [];
        if (this.modalBs) {
            this.modalBs.hide();
        }
        this._paintAll();
        this._renderAllQuadrants();
        this._emitChange();
    };

    function dcmtResolveStylesheetHref(linkEl) {
        if (!linkEl || !linkEl.getAttribute('href')) {
            return '';
        }
        try {
            return new URL(linkEl.href, global.location.href).href;
        } catch (e) {
            return linkEl.href;
        }
    }

    DcmtOdontogram.prototype.printSection = function () {
        var section = this.root.closest('.dcmt-odontogram-dual-block')
            || this.root.closest('.dcmt-odontogram-section-wrap');
        if (!section) {
            return;
        }

        var cloned = section.cloneNode(true);
        section.querySelectorAll('textarea').forEach(function (ta) {
            if (!ta.id) {
                return;
            }
            var cloneTa = cloned.querySelector('#' + ta.id);
            if (cloneTa) {
                cloneTa.value = ta.value;
                cloneTa.textContent = ta.value;
            }
        });

        var actionBar = cloned.querySelector('.dcmt-odontogram-actions');
        if (actionBar) {
            actionBar.remove();
        }
        var modalClone = document.getElementById('dcmtOdontogramToothModal');
        if (modalClone) {
            modalClone.remove();
        }
        var hidden = cloned.querySelector('#odontogram_data');
        if (hidden) {
            hidden.remove();
        }
        var initialJson = cloned.querySelector('#dcmt-odontogram-initial');
        if (initialJson) {
            initialJson.remove();
        }

        var cssLink = document.querySelector('link[href*="odontogram.css"]');
        var cssHref = dcmtResolveStylesheetHref(cssLink);
        var bootstrapCss = document.querySelector('link[href*="bootstrap.min.css"],link[href*="bootstrap"]');
        var bootstrapHref = dcmtResolveStylesheetHref(bootstrapCss);
        var faHref =
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css';

        var headParts = [
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>Odontogram (FDI)</title>'
        ];
        if (bootstrapHref) {
            headParts.push('<link rel="stylesheet" href="' + bootstrapHref + '">');
        }
        headParts.push('<link rel="stylesheet" href="' + faHref + '">');
        if (cssHref) {
            headParts.push('<link rel="stylesheet" href="' + cssHref + '">');
        }
        headParts.push(
            '<style>',
            '@page{margin:12mm;}',
            'html,body{width:100%;}',
            'body{padding:16px;background:#fff;color:#212529;overflow:visible!important;}',
            '.dcmt-odontogram-root{box-shadow:none!important;margin:0!important;}',
            '.dcmt-odontogram-section-wrap{margin-bottom:0!important;}',
            '.dcmt-crosshair-plate{overflow:visible!important;}',
            '.dcmt-odontogram-row{overflow:visible!important;}',
            '.dcmt-arch-half{overflow:visible!important;}',
            '.dcmt-zona-label{display:block!important;color:#000!important;font-weight:700!important;}',
            'textarea{resize:none;background:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact;}',
            '*{-webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact;}',
            '</style>'
        );

        var html =
            '<!doctype html><html><head>' +
            headParts.join('') +
            '</head><body>' +
            cloned.outerHTML +
            '</body></html>';

        var iframe = document.createElement('iframe');
        iframe.setAttribute('title', 'Odontogram print');
        iframe.setAttribute(
            'style',
            'position:fixed;left:-9999px;top:0;width:1300px;height:1800px;border:0;pointer-events:none'
        );
        document.body.appendChild(iframe);

        var win = iframe.contentWindow;
        var doc = iframe.contentDocument || win.document;
        doc.open();
        doc.write(html);
        doc.close();

        function cleanup() {
            if (iframe.parentNode) {
                iframe.parentNode.removeChild(iframe);
            }
        }

        var printed = false;

        function doPrint() {
            if (printed) {
                return;
            }
            printed = true;
            try {
                win.focus();
                win.print();
            } catch (e) {
                cleanup();
            }
            global.setTimeout(cleanup, 1200);
        }

        var links = doc.querySelectorAll('link[rel="stylesheet"]');
        var pending = links.length;

        function onLinkDone() {
            pending -= 1;
            if (pending <= 0) {
                global.setTimeout(doPrint, 250);
            }
        }

        if (pending === 0) {
            global.setTimeout(doPrint, 500);
        } else {
            links.forEach(function (lnk) {
                lnk.addEventListener('load', onLinkDone, { once: true });
                lnk.addEventListener('error', onLinkDone, { once: true });
            });
            global.setTimeout(doPrint, 2200);
        }
    };

    DcmtOdontogram.prototype._onFormSubmit = function () {
        this.syncHidden();
    };

    DcmtOdontogram.prototype.init = function () {
        this.readonly = this.root.getAttribute('data-readonly') === '1';
        this.chartKey = this.options.chartKey || this.root.getAttribute('data-chart-key') || '';
        if (!this.options.onSync) {
            this.hiddenInput = document.getElementById('odontogram_data');
        }
        var upperEl = this.root.querySelector('.dcmt-odontogram-row-upper')
            || this.root.querySelector('#dcmtOdontogramUpper');
        var lowerEl = this.root.querySelector('.dcmt-odontogram-row-lower')
            || this.root.querySelector('#dcmtOdontogramLower');
        renderRow(upperEl, UPPER_LEFT_MAIN, UPPER_LEFT_SECONDARY, UPPER_RIGHT_MAIN, UPPER_RIGHT_SECONDARY);
        renderRow(lowerEl, LOWER_LEFT_SECONDARY, LOWER_LEFT_MAIN, LOWER_RIGHT_SECONDARY, LOWER_RIGHT_MAIN);

        if (!this.readonly) {
            this.root.addEventListener('click', this._onToothInteract);
            this._bindLegendInteractions();
            this._renderLegendSelection();
        }

        var self = this;

        var form = this.root.closest('form');
        if (form) {
            form.addEventListener('submit', this._onFormSubmit);
        }

        this._applyStateColors();

        var initial = this.options.initial || {};
        this._loadPayload(initial);
    };

    DcmtOdontogram.parseInitialFromDom = function (root) {
        var el = null;
        if (root) {
            var instanceSuffix = root.getAttribute('data-instance-suffix')
                || root.getAttribute('data-chart-key')
                || '';
            if (instanceSuffix) {
                el = document.getElementById('dcmt-odontogram-initial-' + instanceSuffix);
            }
            if (!el) {
                var chartKey = root.getAttribute('data-chart-key') || '';
                if (chartKey) {
                    el = document.getElementById('dcmt-odontogram-initial-' + chartKey);
                }
            }
            if (!el) {
                el = root.parentElement && root.parentElement.querySelector('[id^="dcmt-odontogram-initial"]');
            }
        }
        if (!el) {
            el = document.getElementById('dcmt-odontogram-initial');
        }
        if (!el) {
            return {};
        }
        var raw = el.textContent || '';
        try {
            var j = JSON.parse(raw);
            return typeof j === 'object' && j !== null ? j : {};
        } catch (e) {
            return {};
        }
    };

    DcmtOdontogram.activateInitialSolutionTab = function (container) {
        var scope = container || document;
        var solutionTabBtn = scope.querySelector('#dcmt-od-tab-solution-btn');
        if (!solutionTabBtn) {
            return;
        }
        if (typeof bootstrap !== 'undefined' && bootstrap.Tab) {
            bootstrap.Tab.getOrCreateInstance(solutionTabBtn).show();
            return;
        }
        var problemTabBtn = scope.querySelector('#dcmt-od-tab-problem-btn');
        var tabContent = scope.querySelector('.dcmt-odontogram-tab-content');
        if (problemTabBtn) {
            problemTabBtn.classList.remove('active');
            problemTabBtn.setAttribute('aria-selected', 'false');
        }
        solutionTabBtn.classList.add('active');
        solutionTabBtn.setAttribute('aria-selected', 'true');
        if (tabContent) {
            tabContent.querySelectorAll('.tab-pane').forEach(function (pane) {
                var isSolution = pane.id === 'dcmt-od-tab-solution';
                pane.classList.toggle('show', isSolution);
                pane.classList.toggle('active', isSolution);
            });
        }
    };

    DcmtOdontogram.initDualForm = function (wrapEl) {
        if (!wrapEl || typeof window.DcmtOdontogram === 'undefined') {
            return [];
        }
        var hidden = document.getElementById('odontogram_data');
        var roots = wrapEl.querySelectorAll('.dcmt-odontogram-root');
        var instances = [];
        var pending = [];

        function syncCombinedHidden() {
            if (!hidden) {
                return;
            }
            var doc = {};
            instances.forEach(function (inst) {
                var key = inst.options.chartKey || inst.chartKey;
                if (key) {
                    doc[key] = inst.getPayload();
                }
            });
            hidden.value = documentPayloadIsEmpty(doc) ? '{}' : JSON.stringify(doc);
        }

        roots.forEach(function (root) {
            if (root.getAttribute('data-readonly') === '1') {
                return;
            }
            var chartKey = root.getAttribute('data-chart-key') || '';
            var initial = window.DcmtOdontogram.parseInitialFromDom(root);
            var i18n = window.DcmtOdontogram.readTrans(root);
            var treatments = window.DcmtOdontogram.readTreatments(root);
            var stateColors = window.DcmtOdontogram.readStateColors(root);
            var problemStates = window.DcmtOdontogram.readProblemStates(root);
            var patientId = root.getAttribute('data-patient-id') || '';
            var inst = new window.DcmtOdontogram(root, {
                initial: initial,
                i18n: i18n,
                treatments: treatments,
                problemStates: problemStates,
                stateColors: stateColors,
                patientId: patientId,
                chartKey: chartKey,
                onSync: syncCombinedHidden
            });
            instances.push(inst);
            pending.push(inst);
        });

        pending.forEach(function (inst) {
            inst.init();
        });

        window.DcmtOdontogram.activateInitialSolutionTab(wrapEl);

        var solutionTabBtn = wrapEl.querySelector('#dcmt-od-tab-solution-btn');
        if (solutionTabBtn) {
            solutionTabBtn.addEventListener('shown.bs.tab', function () {
                instances.forEach(function (inst) {
                    if (inst.chartKey === 'solution') {
                        inst._paintAll();
                        inst._renderAllQuadrants();
                    }
                });
            });
        }

        syncCombinedHidden();

        var form = wrapEl.closest('form');
        if (form) {
            form.addEventListener('submit', function (ev) {
                syncCombinedHidden();
            });
        }

        window.dcmtPatientOdontogramInstances = instances;
        window.dcmtPatientOdontogram = instances[0] || null;

        return instances;
    };

    DcmtOdontogram.initReadonlyRoots = function (container) {
        var scope = container || document;
        var roots = scope.querySelectorAll('.dcmt-odontogram-root');
        var instances = [];
        var pending = [];
        roots.forEach(function (root) {
            if (root.getAttribute('data-readonly') !== '1') {
                return;
            }
            var initial = window.DcmtOdontogram.parseInitialFromDom(root);
            var i18n = window.DcmtOdontogram.readTrans(root);
            var treatments = window.DcmtOdontogram.readTreatments(root);
            var stateColors = window.DcmtOdontogram.readStateColors(root);
            var problemStates = window.DcmtOdontogram.readProblemStates(root);
            var patientId = root.getAttribute('data-patient-id') || '';
            var chartKey = root.getAttribute('data-chart-key') || '';
            var inst = new window.DcmtOdontogram(root, {
                initial: initial,
                i18n: i18n,
                treatments: treatments,
                problemStates: problemStates,
                stateColors: stateColors,
                patientId: patientId,
                chartKey: chartKey
            });
            instances.push(inst);
            pending.push(inst);
        });
        pending.forEach(function (inst) {
            inst.init();
        });

        window.DcmtOdontogram.activateInitialSolutionTab(scope);

        var solutionTabBtn = scope.querySelector('#dcmt-od-tab-solution-btn');
        if (solutionTabBtn) {
            solutionTabBtn.addEventListener('shown.bs.tab', function () {
                instances.forEach(function (inst) {
                    if (inst.chartKey === 'solution') {
                        inst._paintAll();
                        inst._renderAllQuadrants();
                    }
                });
            });
        }

        return instances;
    };

    DcmtOdontogram.readTrans = function (root) {
        try {
            var raw = root.getAttribute('data-trans') || '{}';
            return JSON.parse(raw);
        } catch (e) {
            return {};
        }
    };

    DcmtOdontogram.readTreatments = function (root) {
        try {
            var raw = root.getAttribute('data-treatments') || '[]';
            var list = JSON.parse(raw);
            return Array.isArray(list) ? list : [];
        } catch (e) {
            return [];
        }
    };

    DcmtOdontogram.readProblemStates = function (root) {
        try {
            var raw = root.getAttribute('data-problem-states') || '[]';
            var list = JSON.parse(raw);
            return Array.isArray(list) ? list : [];
        } catch (e) {
            return [];
        }
    };

    DcmtOdontogram.readStateColors = function (root) {
        try {
            var raw = root.getAttribute('data-state-colors') || '{}';
            var map = JSON.parse(raw);
            return map && typeof map === 'object' ? map : {};
        } catch (e) {
            return {};
        }
    };

    global.DcmtOdontogram = DcmtOdontogram;
    global.dcmtOdontogramCreateTooth = createTooth;
    global.dcmtOdontogramAllTeeth = ALL_TEETH;
})(typeof window !== 'undefined' ? window : this);

document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.DcmtOdontogram === 'undefined') {
        return;
    }
    var printWrap = document.getElementById('dcmtOdontogramPrintWrap');
    if (printWrap) {
        window.DcmtOdontogram.initReadonlyRoots(printWrap);
        return;
    }
    var dualWrap = document.getElementById('dcmtOdontogramDualWrap');
    if (dualWrap) {
        if (document.getElementById('odontogram_data')) {
            window.DcmtOdontogram.initDualForm(dualWrap);
        } else {
            window.DcmtOdontogram.initReadonlyRoots(dualWrap);
        }
        return;
    }
    var root = document.getElementById('dcmtOdontogramRoot');
    if (!root) {
        return;
    }
    var initial = window.DcmtOdontogram.parseInitialFromDom(root);
    var i18n = window.DcmtOdontogram.readTrans(root);
    var treatments = window.DcmtOdontogram.readTreatments(root);
    var stateColors = window.DcmtOdontogram.readStateColors(root);
    var problemStates = window.DcmtOdontogram.readProblemStates(root);
    var patientId = root.getAttribute('data-patient-id') || '';
    var chartKey = root.getAttribute('data-chart-key') || '';
    var inst = new window.DcmtOdontogram(root, {
        initial: initial,
        i18n: i18n,
        treatments: treatments,
        problemStates: problemStates,
        stateColors: stateColors,
        patientId: patientId,
        chartKey: chartKey
    });
    inst.init();
    window.dcmtPatientOdontogram = inst;
});

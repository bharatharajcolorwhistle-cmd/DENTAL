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
        { key: 'default', labelKey: 'stateDefault', legend: 'default' },
        { key: 'damaged', labelKey: 'stateDamaged', legend: 'damaged' },
        { key: 'filling', labelKey: 'stateFilling', legend: 'filling' },
        { key: 'missing', labelKey: 'stateMissing', legend: 'missing' },
        { key: 'crown', labelKey: 'stateCrown', legend: 'crown' },
        { key: 'implant', labelKey: 'stateImplant', legend: 'implant' }
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

    function treatmentMatchesZone(treatment, zoneType) {
        if (!treatment || !treatment.zone) {
            return true;
        }
        if (treatment.zone === 'both') {
            return true;
        }
        return treatment.zone === zoneType;
    }

    function isWholeToothState(stateKey) {
        return WHOLE_TOOTH_STATES.indexOf(stateKey) >= 0;
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

    function toothIsFullWholeToothState(toothData, stateKey) {
        if (!toothData || !isWholeToothState(stateKey)) {
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

    function DcmtOdontogram(root, options) {
        this.root = root;
        this.options = options || {};
        this.treatments = Array.isArray(options.treatments) ? options.treatments : [];
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
        this._onToothInteract = this._onToothInteract.bind(this);
        this._onFormSubmit = this._onFormSubmit.bind(this);
    }

    DcmtOdontogram.prototype._emitChange = function () {
        this.syncHidden();
        if (typeof this.options.onChange === 'function') {
            this.options.onChange(this.getPayload());
        }
    };

    DcmtOdontogram.prototype.getPayload = function () {
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

    DcmtOdontogram.prototype._findEntryIndex = function (zoneKey, quadrant, tooth) {
        var entries = this._getEntries(zoneKey, quadrant);
        for (var i = 0; i < entries.length; i++) {
            if (String(entries[i].tooth) === String(tooth)) {
                return i;
            }
        }
        return -1;
    };

    DcmtOdontogram.prototype._getEntryForTooth = function (tooth) {
        var zq = this._getToothZoneQuadrant(tooth);
        var idx = this._findEntryIndex(zq.zoneKey, zq.quadrant, tooth);
        if (idx < 0) {
            return null;
        }
        return this.state[zq.zoneKey][zq.quadrant][idx];
    };

    DcmtOdontogram.prototype._ensureEntry = function (tooth) {
        var zq = this._getToothZoneQuadrant(tooth);
        var entries = this._getEntries(zq.zoneKey, zq.quadrant);
        var idx = this._findEntryIndex(zq.zoneKey, zq.quadrant, tooth);
        if (idx >= 0) {
            return entries[idx];
        }
        var entry = {
            tooth: String(tooth),
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
        var existing = this._getEntryForTooth(tooth);
        var savedTreatments = existing && Array.isArray(existing.treatments)
            ? existing.treatments.slice()
            : [];
        var hasTr = savedTreatments.length > 0;
        var hasSections = this._toothHasChartSections(this.state.teeth[tooth]);
        var t = this.state.teeth[tooth];
        var cond = null;
        if (t) {
            if (toothIsFullWholeToothState(t, 'filling')) {
                cond = 'filling';
            } else if (toothIsFullWholeToothState(t, 'crown')) {
                cond = 'crown';
            } else {
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

    DcmtOdontogram.prototype._removeEntryForTooth = function (tooth) {
        var zq = this._getToothZoneQuadrant(tooth);
        var idx = this._findEntryIndex(zq.zoneKey, zq.quadrant, tooth);
        if (idx >= 0) {
            this.state[zq.zoneKey][zq.quadrant].splice(idx, 1);
        }
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

    DcmtOdontogram.prototype._stateLabel = function (stateKey) {
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
        if (toothIsFullWholeToothState(t, 'filling')) {
            return self._stateLabel('filling') + ' (' + wholeLbl + ')';
        }
        if (toothIsFullWholeToothState(t, 'crown')) {
            return self._stateLabel('crown') + ' (' + wholeLbl + ')';
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

    DcmtOdontogram.prototype._filterTreatmentsForEntry = function (entry, zoneType) {
        var self = this;
        return this.treatments.filter(function (tr) {
            if (!treatmentMatchesZone(tr, zoneType)) {
                return false;
            }
            if (tr.toothState && entry.condition && tr.toothState !== entry.condition) {
                return false;
            }
            if (tr.toothState && !entry.condition) {
                return false;
            }
            return true;
        });
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
        var hasState = this._toothHasChartSections(t);
        var entry = this._getEntryForTooth(tooth);
        var hasTr = entry && entry.treatments && entry.treatments.length > 0;
        var hasRecord = hasState || hasTr;
        if (foot) {
            foot.classList.toggle('has-state', hasState);
            foot.classList.toggle('has-treatments', !!hasTr);
            foot.innerHTML = '';
            foot.removeAttribute('title');
        }
        if (cell) {
            cell.classList.toggle('dcmt-tooth-cell--has-record', hasRecord);
            cell.setAttribute('title', hasRecord ? this._formatToothConditionsSummary(tooth) : '');
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
        this._migrateLoadedData();
        this._normalizeZonaEntries();
        this._normalizeWholeToothStates();
        if (this.readonly) {
            this._syncViewQuadrantEntriesFromTeeth();
        } else {
            ALL_TEETH.forEach(function (t) {
                if (this._toothHasChartSections(this.state.teeth[t]) || this._getEntryForTooth(t)) {
                    this._syncEntryFromTooth(t);
                }
            }, this);
        }
        this._syncTeethFromZonaEntries();
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
            WHOLE_TOOTH_STATES.forEach(function (st) {
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
        if (isWholeToothState(st)) {
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
        var self = this;
        [ZONA_POSTERIOR_KEY, ZONA_ANTERIOR_KEY].forEach(function (zoneKey) {
            ['tl', 'tr', 'bl', 'br'].forEach(function (q) {
                self._getEntries(zoneKey, q).forEach(function (entry) {
                    if (entry.condition && isWholeToothState(entry.condition)) {
                        self._applyConditionToTooth(entry.tooth, entry.condition, null);
                    }
                });
            });
        });
    };

    DcmtOdontogram.prototype._updateModalBlockHint = function () {
        if (!this._blockHint || !this._condSel) {
            return;
        }
        var i18n = this.options.i18n || {};
        var cond = this._condSel.value;
        var needsBlock = cond && cond !== 'default' && !isWholeToothState(cond);
        if (!needsBlock) {
            this._blockHint.hidden = true;
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
            var row;
            if (self.readonly) {
                row = document.createElement('div');
                row.className = 'dcmt-zona-q-entry dcmt-zona-q-entry--static';
                row.setAttribute('role', 'listitem');
            } else {
                row = document.createElement('button');
                row.type = 'button';
                row.className = 'dcmt-zona-q-entry';
                row.addEventListener('click', function () {
                    self._openToothModal(entry.tooth);
                });
            }
            row.dataset.tooth = entry.tooth;
            var cond = self._formatToothConditionsSummary(entry.tooth);
            if (!cond && entry.condition) {
                cond = self._stateLabel(entry.condition);
            }
            if (!cond) {
                cond = '—';
            }
            var trList = Array.isArray(entry.treatments) ? entry.treatments : [];
            var trTxt = trList.length ? trList.join(', ') : '';
            var trLabel = i18n.clinicalTreatments || 'Treatments';
            var trLine = trList.length
                ? '<span class="dcmt-zona-q-entry-tr"><span class="dcmt-zona-q-entry-tr-label">' + trLabel + ':</span> ' + trTxt + '</span>'
                : '';
            row.innerHTML =
                '<span class="dcmt-zona-q-entry-tooth">' + (i18n.toothLabel || 'Tooth') + ' ' + entry.tooth + '</span>' +
                '<span class="dcmt-zona-q-entry-cond">' + cond + '</span>' +
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
            condSel.innerHTML = '';
            TOOTH_STATE_OPTIONS.forEach(function (opt) {
                var o = document.createElement('option');
                o.value = opt.key;
                o.textContent = i18n[opt.labelKey] || opt.key;
                condSel.appendChild(o);
            });
            condSel.addEventListener('change', function () {
                var active = DcmtOdontogram._activeInstance;
                if (!active) {
                    return;
                }
                active._updateModalBlockHint();
                active._populateModalTreatmentOptions();
            });
        }
        if (trAddBtn && trAdd) {
            trAddBtn.addEventListener('click', function () {
                var active = DcmtOdontogram._activeInstance;
                if (!active) {
                    return;
                }
                var name = trAdd.value;
                if (!name) {
                    return;
                }
                if (active.modalDraftTreatments.indexOf(name) < 0) {
                    active.modalDraftTreatments.push(name);
                }
                trAdd.value = '';
                active._renderModalChips();
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
                if (global.confirm(i18nActive.confirmResetTooth || 'Remove condition and treatments for this tooth?')) {
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
            }
        });
        if (typeof global.bootstrap !== 'undefined' && global.bootstrap.Modal) {
            DcmtOdontogram._sharedModalBs = new global.bootstrap.Modal(this.modalEl);
        }
        DcmtOdontogram._titleEl = titleEl;
        DcmtOdontogram._zoneEl = zoneEl;
        DcmtOdontogram._blockHint = this.modalEl.querySelector('.dcmt-od-modal-block-hint');
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
        this._condSel = DcmtOdontogram._condSel;
        this._trAdd = DcmtOdontogram._trAdd;
        this._noTr = DcmtOdontogram._noTr;
        this._titleTpl = DcmtOdontogram._titleTpl;
    };

    DcmtOdontogram.prototype._commitPendingModalTreatment = function () {
        if (!this._trAdd) {
            return;
        }
        var name = (this._trAdd.value || '').trim();
        if (!name) {
            return;
        }
        if (this.modalDraftTreatments.indexOf(name) < 0) {
            this.modalDraftTreatments.push(name);
        }
        this._trAdd.value = '';
    };

    DcmtOdontogram.prototype._populateModalTreatmentOptions = function () {
        if (!this._trAdd || !this.modalTooth) {
            return;
        }
        var i18n = this.options.i18n || {};
        var cond = this._condSel ? this._condSel.value : 'default';
        var zq = this._getToothZoneQuadrant(this.modalTooth);
        var entry = {
            tooth: String(this.modalTooth),
            condition: cond === 'default' ? null : cond,
            treatments: this.modalDraftTreatments.slice()
        };
        var opt0 = document.createElement('option');
        opt0.value = '';
        opt0.textContent = i18n.chooseTreatment || 'Choose treatment…';
        this._trAdd.innerHTML = '';
        this._trAdd.appendChild(opt0);
        var inSelect = { '': true };
        var hasOpts = false;
        if (cond && cond !== 'default') {
            this._filterTreatmentsForEntry(entry, zq.zoneType).forEach(function (tr) {
                if (entry.treatments.indexOf(tr.name) >= 0 || inSelect[tr.name]) {
                    return;
                }
                var o = document.createElement('option');
                o.value = tr.name;
                o.textContent = tr.name;
                this._trAdd.appendChild(o);
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
            this._trAdd.appendChild(o);
            inSelect[name] = true;
            hasOpts = true;
        }, this);
        this._trAdd.disabled = !cond || cond === 'default';
        if (this._noTr) {
            this._noTr.hidden = !(cond && cond !== 'default' && !hasOpts && this.treatments.length > 0);
        }
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
            chip.className = 'badge rounded-pill text-bg-primary dcmt-zona-treatment-chip';
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
            if (toothIsFullWholeToothState(t, 'filling')) {
                cond = 'filling';
            } else if (toothIsFullWholeToothState(t, 'crown')) {
                cond = 'crown';
            } else {
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
        this.modalDraftTreatments = entry && Array.isArray(entry.treatments)
            ? entry.treatments.slice()
            : [];
        if (this._titleEl) {
            var title = (this._titleTpl || 'Tooth %s').replace('%s', tooth);
            this._titleEl.textContent = title;
        }
        if (this._zoneEl) {
            this._zoneEl.textContent = this._getZoneContextLabel(tooth);
        }
        if (this._condSel) {
            this._condSel.value = cond;
        }
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
        this._commitPendingModalTreatment();
        var cond = this._condSel ? this._condSel.value : 'default';
        var treatments = this.modalDraftTreatments.slice();

        if (cond && cond !== 'default' && !isWholeToothState(cond) && !section) {
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
        } else if (isWholeToothState(cond)) {
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
        this._removeEntryForTooth(tooth);
        this._clearAllToothSections(tooth);
        this.activeSection = null;
        this._refreshToothFootprint(tooth);
        this._renderAllQuadrants();
        this._emitChange();
        this.modalDraftTreatments = [];
        if (this._condSel) {
            this._condSel.value = 'default';
        }
        this._updateModalBlockHint();
        this._renderModalChips();
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
        var section = sectionEl ? sectionEl.dataset.section : null;
        this._openToothModal(tooth, section);
    };

    DcmtOdontogram.prototype._paintAll = function () {
        var self = this;
        ALL_TEETH.forEach(function (t) {
            SECTION_ORDER.forEach(function (sec) {
                var st = (self.state.teeth[t] && self.state.teeth[t][sec]) || 'default';
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
            this._initModal();
            this._bindSharedModalRefs();
        }

        var self = this;

        var printBtn = this.root.querySelector('.dcmt-odontogram-print-btn')
            || this.root.querySelector('#dcmtOdontogramPrintBtn');
        if (printBtn) {
            printBtn.addEventListener('click', function () {
                self.printSection();
            });
        }

        var form = this.root.closest('form');
        if (form) {
            form.addEventListener('submit', this._onFormSubmit);
        }

        var initial = this.options.initial || {};
        this._loadPayload(initial);
    };

    DcmtOdontogram.parseInitialFromDom = function (root) {
        var el = null;
        if (root) {
            var chartKey = root.getAttribute('data-chart-key') || '';
            if (chartKey) {
                el = document.getElementById('dcmt-odontogram-initial-' + chartKey);
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

    DcmtOdontogram.initDualForm = function (wrapEl) {
        if (!wrapEl || typeof window.DcmtOdontogram === 'undefined') {
            return [];
        }
        var hidden = document.getElementById('odontogram_data');
        var roots = wrapEl.querySelectorAll('.dcmt-odontogram-root');
        var instances = [];

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
            var patientId = root.getAttribute('data-patient-id') || '';
            var inst = new window.DcmtOdontogram(root, {
                initial: initial,
                i18n: i18n,
                treatments: treatments,
                patientId: patientId,
                chartKey: chartKey,
                onSync: syncCombinedHidden
            });
            instances.push(inst);
            inst.init();
        });

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
        roots.forEach(function (root) {
            if (root.getAttribute('data-readonly') !== '1') {
                return;
            }
            var initial = window.DcmtOdontogram.parseInitialFromDom(root);
            var i18n = window.DcmtOdontogram.readTrans(root);
            var treatments = window.DcmtOdontogram.readTreatments(root);
            var patientId = root.getAttribute('data-patient-id') || '';
            var chartKey = root.getAttribute('data-chart-key') || '';
            var inst = new window.DcmtOdontogram(root, {
                initial: initial,
                i18n: i18n,
                treatments: treatments,
                patientId: patientId,
                chartKey: chartKey
            });
            inst.init();
            instances.push(inst);
        });
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

    global.DcmtOdontogram = DcmtOdontogram;
    global.dcmtOdontogramCreateTooth = createTooth;
    global.dcmtOdontogramAllTeeth = ALL_TEETH;
})(typeof window !== 'undefined' ? window : this);

document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.DcmtOdontogram === 'undefined') {
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
    var patientId = root.getAttribute('data-patient-id') || '';
    var chartKey = root.getAttribute('data-chart-key') || '';
    var inst = new window.DcmtOdontogram(root, {
        initial: initial,
        i18n: i18n,
        treatments: treatments,
        patientId: patientId,
        chartKey: chartKey
    });
    inst.init();
    window.dcmtPatientOdontogram = inst;
});

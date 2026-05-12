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
        this.state = {
            teeth: {},
            zonaPosterior: { tl: '', tr: '', bl: '', br: '' },
            zonaAnterior: { tl: '', tr: '', bl: '', br: '' }
        };
        this.hiddenInput = null;
        this._onSectionClick = this._onSectionClick.bind(this);
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
        if (!this.hiddenInput) {
            return;
        }
        var p = this.getPayload();
        var emptyTeeth = Object.keys(p.teeth).length === 0;
        var emptyZPosterior = !p.zonaPosterior.tl && !p.zonaPosterior.tr && !p.zonaPosterior.bl && !p.zonaPosterior.br;
        var emptyZAnterior = !p.zonaAnterior.tl && !p.zonaAnterior.tr && !p.zonaAnterior.bl && !p.zonaAnterior.br;
        var emptyZ = emptyZPosterior && emptyZAnterior;
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

    DcmtOdontogram.prototype._refreshToothFootprint = function (tooth) {
        var foot = this.root.querySelector('.dcmt-tooth-footprint[data-tooth-foot="' + tooth + '"]');
        if (!foot) {
            return;
        }
        var t = this.state.teeth[tooth];
        var hasState = !!(t && Object.keys(t).length > 0);
        foot.classList.toggle('has-state', hasState);
        foot.removeAttribute('title');
    };

    DcmtOdontogram.prototype._loadPayload = function (data) {
        this.state = {
            teeth: {},
            zonaPosterior: { tl: '', tr: '', bl: '', br: '' },
            zonaAnterior: { tl: '', tr: '', bl: '', br: '' }
        };
        if (data && typeof data === 'object') {
            if (data.teeth && typeof data.teeth === 'object') {
                this.state.teeth = JSON.parse(JSON.stringify(data.teeth));
            }
            if (typeof data.zonaPosterior === 'string') {
                this.state.zonaPosterior.tl = data.zonaPosterior;
            }
            if (typeof data.zonaPosterior === 'object' && data.zonaPosterior) {
                this.state.zonaPosterior.tl = data.zonaPosterior.tl || '';
                this.state.zonaPosterior.tr = data.zonaPosterior.tr || '';
                this.state.zonaPosterior.bl = data.zonaPosterior.bl || '';
                this.state.zonaPosterior.br = data.zonaPosterior.br || '';
            }
            if (typeof data.zonaAnterior === 'string') {
                this.state.zonaAnterior.tl = data.zonaAnterior;
            }
            if (typeof data.zonaAnterior === 'object' && data.zonaAnterior) {
                this.state.zonaAnterior.tl = data.zonaAnterior.tl || '';
                this.state.zonaAnterior.tr = data.zonaAnterior.tr || '';
                this.state.zonaAnterior.bl = data.zonaAnterior.bl || '';
                this.state.zonaAnterior.br = data.zonaAnterior.br || '';
            }
        }
        this._paintAll();
        this._syncZoneInputsFromState();
        this._emitChange();
    };

    DcmtOdontogram.prototype._syncZoneInputsFromState = function () {
        var self = this;
        ['tl', 'tr', 'bl', 'br'].forEach(function (q) {
            var zp = self.root.querySelector('#dcmtZonaPosterior_' + q);
            var za = self.root.querySelector('#dcmtZonaAnterior_' + q);
            if (zp) {
                zp.value = self.state.zonaPosterior[q] || '';
            }
            if (za) {
                za.value = self.state.zonaAnterior[q] || '';
            }
        });
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
    };

    DcmtOdontogram.prototype._onSectionClick = function (ev) {
        var el = ev.target.closest('.dcmt-tooth-section');
        if (!el || !this.root.contains(el)) {
            return;
        }
        var tooth = el.dataset.tooth;
        var section = el.dataset.section;
        if (!tooth || !section) {
            return;
        }
        if (!this.state.teeth[tooth]) {
            this.state.teeth[tooth] = {};
        }
        var cur = this.state.teeth[tooth][section] || 'default';
        var nw = nextState(cur);
        if (nw === 'default') {
            delete this.state.teeth[tooth][section];
            if (Object.keys(this.state.teeth[tooth]).length === 0) {
                delete this.state.teeth[tooth];
            }
        } else {
            this.state.teeth[tooth][section] = nw;
        }
        this._applySectionState(tooth, section, nw === 'default' ? 'default' : nw);
        this._refreshToothFootprint(tooth);
        this._emitChange();
    };

    DcmtOdontogram.prototype.reset = function () {
        this.state = {
            teeth: {},
            zonaPosterior: { tl: '', tr: '', bl: '', br: '' },
            zonaAnterior: { tl: '', tr: '', bl: '', br: '' }
        };
        this._syncZoneInputsFromState();
        this._paintAll();
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
        var section = this.root.closest('.dcmt-odontogram-section-wrap');
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
        this.hiddenInput = document.getElementById('odontogram_data');
        var upperEl = this.root.querySelector('#dcmtOdontogramUpper');
        var lowerEl = this.root.querySelector('#dcmtOdontogramLower');
        renderRow(upperEl, UPPER_LEFT_MAIN, UPPER_LEFT_SECONDARY, UPPER_RIGHT_MAIN, UPPER_RIGHT_SECONDARY);
        renderRow(lowerEl, LOWER_LEFT_SECONDARY, LOWER_LEFT_MAIN, LOWER_RIGHT_SECONDARY, LOWER_RIGHT_MAIN);

        this.root.addEventListener('click', this._onSectionClick);

        var self = this;
        ['tl', 'tr', 'bl', 'br'].forEach(function (q) {
            var zp = self.root.querySelector('#dcmtZonaPosterior_' + q);
            var za = self.root.querySelector('#dcmtZonaAnterior_' + q);
            if (zp) {
                zp.addEventListener('input', function () {
                    self.state.zonaPosterior[q] = zp.value;
                    self._emitChange();
                });
            }
            if (za) {
                za.addEventListener('input', function () {
                    self.state.zonaAnterior[q] = za.value;
                    self._emitChange();
                });
            }
        });

        var resetBtn = this.root.querySelector('#dcmtOdontogramResetBtn');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                var msg = (self.options.i18n && self.options.i18n.confirmReset) || 'Reset odontogram?';
                if (global.confirm(msg)) {
                    self.reset();
                }
            });
        }

        var printBtn = this.root.querySelector('#dcmtOdontogramPrintBtn');
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

    DcmtOdontogram.parseInitialFromDom = function () {
        var el = document.getElementById('dcmt-odontogram-initial');
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

    DcmtOdontogram.readTrans = function (root) {
        try {
            var raw = root.getAttribute('data-trans') || '{}';
            return JSON.parse(raw);
        } catch (e) {
            return {};
        }
    };

    global.DcmtOdontogram = DcmtOdontogram;
    global.dcmtOdontogramCreateTooth = createTooth;
    global.dcmtOdontogramAllTeeth = ALL_TEETH;
})(typeof window !== 'undefined' ? window : this);

document.addEventListener('DOMContentLoaded', function () {
    var root = document.getElementById('dcmtOdontogramRoot');
    if (!root || typeof window.DcmtOdontogram === 'undefined') {
        return;
    }
    var initial = window.DcmtOdontogram.parseInitialFromDom();
    var i18n = window.DcmtOdontogram.readTrans(root);
    var patientId = root.getAttribute('data-patient-id') || '';
    var inst = new window.DcmtOdontogram(root, { initial: initial, i18n: i18n, patientId: patientId });
    inst.init();
    window.dcmtPatientOdontogram = inst;
});

/*
 * Unit display and input conversion (D-038). The only place in the UI that knows about kg and L.
 *
 * Storage and API are always integer base units: g, ml, piece. Every rule here mirrors the
 * table in docs/ui.md, and public/js/units.test.html runs every row of it.
 *
 * Decimal input is converted with STRING arithmetic (split on the point, pad to 3 digits),
 * never `value * 1000`. In floating point, 1.005 * 1000 is 1004.9999999999999, which would
 * silently put 1 g less into stock than the manager typed.
 */
(function (root) {
  'use strict';

  var PIECE = 'piece';
  var MAX_DIGITS = 12; // keeps every result a safe integer

  // The big unit each base unit scales to at 1,000 and above. Pieces have none.
  var BIG = { g: 'kg', ml: 'L' };
  var BASE_OF = { kg: 'g', L: 'ml', g: 'g', ml: 'ml' };

  /** Thousands separators on an integer string: "2400" becomes "2,400". */
  function group(digits) {
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  /** Splits a signed integer into sign, whole thousands and remainder, with integer maths only. */
  function splitThousands(base) {
    var neg = base < 0;
    var abs = Math.abs(base);
    return { neg: neg, whole: Math.floor(abs / 1000), rest: abs % 1000 };
  }

  /** "1" + "005" -> "1.005"; "2" + "400" -> "2.4"; "52" + "000" -> "52". */
  function joinDecimal(whole, restPadded) {
    var frac = restPadded.replace(/0+$/, '');
    return group(String(whole)) + (frac ? '.' + frac : '');
  }

  function pieceLabel(base) {
    return Math.abs(base) === 1 ? 'pc' : 'pcs';
  }

  /**
   * How a stored quantity is shown.
   * Returns { text, exact, unit, scaled }. `exact` is the base value for the hover title.
   */
  function display(base, unit) {
    base = Number(base);
    var neg = base < 0 ? '-' : '';

    if (unit === PIECE) {
      var pcs = neg + group(String(Math.abs(base))) + ' ' + pieceLabel(base);
      return { text: pcs, exact: pcs, unit: pieceLabel(base), scaled: false };
    }

    var exact = neg + group(String(Math.abs(base))) + ' ' + unit;
    var big = BIG[unit];

    if (!big || Math.abs(base) < 1000) {
      return { text: exact, exact: exact, unit: unit, scaled: false };
    }

    var parts = splitThousands(base);
    var text = neg + joinDecimal(parts.whole, String(parts.rest).padStart(3, '0')) + ' ' + big;
    return { text: text, exact: exact, unit: big, scaled: true };
  }

  /** The unit buttons a quantity input offers for a base unit. Pieces have no switch. */
  function modesFor(unit) {
    if (unit === 'g') return ['g', 'kg'];
    if (unit === 'ml') return ['ml', 'L'];
    return [unit];
  }

  /** PO lines and deliveries start in kg and L, recipe lines in g and ml (ui.md). */
  function defaultMode(unit, context) {
    var modes = modesFor(unit);
    if (modes.length === 1) return modes[0];
    return context === 'recipe' ? modes[0] : modes[1];
  }

  /**
   * A base quantity expressed in the unit the user is typing in: "2400" with kg -> "2.4".
   * No thousands separator, so the text can go straight back into an input field.
   */
  function toInputText(base, mode) {
    base = Number(base);
    if (mode !== 'kg' && mode !== 'L') return String(base);
    var parts = splitThousands(base);
    var frac = String(parts.rest).padStart(3, '0').replace(/0+$/, '');
    return (parts.neg ? '-' : '') + parts.whole + (frac ? '.' + frac : '');
  }

  /** Same as toInputText but for messages: separators and the unit label ("1.1 kg", "1,100 g"). */
  function formatIn(base, mode) {
    base = Number(base);
    if (mode === 'kg' || mode === 'L') {
      var parts = splitThousands(base);
      return (parts.neg ? '-' : '') + joinDecimal(parts.whole, String(parts.rest).padStart(3, '0')) + ' ' + mode;
    }
    return (base < 0 ? '-' : '') + group(String(Math.abs(base))) + ' ' + mode;
  }

  var WHOLE_MESSAGE = {
    g: 'Grams must be a whole number',
    ml: 'Milliliters must be a whole number',
    piece: 'Pieces must be a whole number',
  };

  /**
   * Turns what the user typed into integer base units, or says why not.
   * `mode` is the unit the field is currently set to: g, kg, ml, L or piece.
   * Returns { ok: true, base } or { ok: false, message }.
   */
  function parseInput(text, mode) {
    var s = String(text == null ? '' : text).trim();
    if (s === '') return { ok: false, message: 'Enter a quantity' };

    var m = /^(-?)(\d+)(?:\.(\d*))?$/.exec(s);
    if (!m) return { ok: false, message: 'Enter a number, for example 2.5' };

    var neg = m[1] === '-';
    var whole = m[2].replace(/^0+(?=\d)/, '');
    // Trailing zeros carry no value: "1.0000" is 1, "1.0005" is not.
    var frac = (m[3] || '').replace(/0+$/, '');

    if (whole.length + 3 > MAX_DIGITS) return { ok: false, message: 'That number is too large' };

    var scaled = mode === 'kg' || mode === 'L';
    var digits;

    if (scaled) {
      if (frac.length > 3) return { ok: false, message: 'Enter at most 3 decimals in ' + mode };
      // "2.4" -> whole "2" + "400" -> 2400, no multiplication involved.
      digits = whole + (frac + '000').slice(0, 3);
    } else {
      if (frac !== '') return { ok: false, message: WHOLE_MESSAGE[mode] || 'Enter a whole number' };
      digits = whole;
    }

    var base = parseInt(digits, 10);
    return { ok: true, base: neg && base !== 0 ? -base : base };
  }

  /**
   * Rewrites quantities inside a server message into the unit the user typed in.
   * Server: "Beef: 1100 g is above the 1050 g limit", user typed kg
   * -> "Beef: 1.1 kg is above the 1.05 kg limit".
   * Only numbers directly followed by g or ml are touched; pieces and other digits stay as sent.
   */
  function rewriteError(message, mode) {
    if (typeof message !== 'string') return message;
    var base = BASE_OF[mode];
    if (!base) return message;

    return message.replace(/(-?\d[\d,]*)\s*(g|ml)\b/g, function (whole, number, unit) {
      if (unit !== base) return whole;
      return formatIn(parseInt(number.replace(/,/g, ''), 10), mode);
    });
  }

  var api = {
    display: display,
    formatIn: formatIn,
    toInputText: toInputText,
    parseInput: parseInput,
    rewriteError: rewriteError,
    modesFor: modesFor,
    defaultMode: defaultMode,
    group: group,
  };

  root.Patty = root.Patty || {};
  root.Patty.units = api;

  if (typeof module !== 'undefined' && module.exports) module.exports = api;
})(typeof window !== 'undefined' ? window : globalThis);

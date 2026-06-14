(function () {
  'use strict';

  var VERSION = 10;
  var SIZE = VERSION * 4 + 17;
  var DATA_CODEWORDS = 274;
  var ECC_CODEWORDS_PER_BLOCK = 18;
  var BLOCK_LAYOUT = [68, 68, 69, 69];
  var ALIGNMENT_POSITIONS = [6, 28, 50];
  var QUIET_ZONE = 4;

  function appendBits(target, value, bitCount) {
    for (var i = bitCount - 1; i >= 0; i -= 1) {
      target.push(((value >>> i) & 1) !== 0);
    }
  }

  function utf8Bytes(value) {
    if (window.TextEncoder) {
      return Array.from(new window.TextEncoder().encode(value));
    }

    var encoded = unescape(encodeURIComponent(value));
    var bytes = [];
    for (var i = 0; i < encoded.length; i += 1) {
      bytes.push(encoded.charCodeAt(i));
    }
    return bytes;
  }

  function gfMultiply(x, y) {
    var z = 0;
    for (var i = 7; i >= 0; i -= 1) {
      z = (z << 1) ^ ((z & 0x80) ? 0x11d : 0);
      if (((y >>> i) & 1) !== 0) {
        z ^= x;
      }
    }
    return z;
  }

  function reedSolomonGenerator(degree) {
    var result = new Array(degree).fill(0);
    result[degree - 1] = 1;
    var root = 1;

    for (var i = 0; i < degree; i += 1) {
      for (var j = 0; j < degree; j += 1) {
        result[j] = gfMultiply(result[j], root);
        if (j + 1 < degree) {
          result[j] ^= result[j + 1];
        }
      }
      root = gfMultiply(root, 0x02);
    }

    return result;
  }

  function reedSolomonRemainder(data, degree) {
    var generator = reedSolomonGenerator(degree);
    var result = new Array(degree).fill(0);

    data.forEach(function (byte) {
      var factor = byte ^ result.shift();
      result.push(0);

      for (var i = 0; i < degree; i += 1) {
        result[i] ^= gfMultiply(generator[i], factor);
      }
    });

    return result;
  }

  function buildCodewords(text) {
    var payload = utf8Bytes(text);
    var maxPayloadBytes = Math.floor((DATA_CODEWORDS * 8 - 4 - 16) / 8);
    if (payload.length > maxPayloadBytes) {
      throw new Error('L’URI TOTP est trop longue pour être encodée dans le QR code.');
    }

    var bits = [];
    appendBits(bits, 0x4, 4);
    appendBits(bits, payload.length, 16);
    payload.forEach(function (byte) {
      appendBits(bits, byte, 8);
    });

    var capacityBits = DATA_CODEWORDS * 8;
    appendBits(bits, 0, Math.min(4, capacityBits - bits.length));
    while (bits.length % 8 !== 0) {
      bits.push(false);
    }

    var codewords = [];
    for (var i = 0; i < bits.length; i += 8) {
      var value = 0;
      for (var j = 0; j < 8; j += 1) {
        value = (value << 1) | (bits[i + j] ? 1 : 0);
      }
      codewords.push(value);
    }

    var padBytes = [0xec, 0x11];
    var padIndex = 0;
    while (codewords.length < DATA_CODEWORDS) {
      codewords.push(padBytes[padIndex % 2]);
      padIndex += 1;
    }

    return codewords;
  }

  function interleaveWithEcc(dataCodewords) {
    var blocks = [];
    var offset = 0;

    BLOCK_LAYOUT.forEach(function (blockSize) {
      var block = dataCodewords.slice(offset, offset + blockSize);
      blocks.push({
        data: block,
        ecc: reedSolomonRemainder(block, ECC_CODEWORDS_PER_BLOCK)
      });
      offset += blockSize;
    });

    var interleaved = [];
    var maxDataLength = Math.max.apply(null, BLOCK_LAYOUT);
    for (var i = 0; i < maxDataLength; i += 1) {
      blocks.forEach(function (block) {
        if (i < block.data.length) {
          interleaved.push(block.data[i]);
        }
      });
    }

    for (var j = 0; j < ECC_CODEWORDS_PER_BLOCK; j += 1) {
      blocks.forEach(function (block) {
        interleaved.push(block.ecc[j]);
      });
    }

    return interleaved;
  }

  function createMatrix() {
    var modules = [];
    var functions = [];
    for (var y = 0; y < SIZE; y += 1) {
      modules.push(new Array(SIZE).fill(false));
      functions.push(new Array(SIZE).fill(false));
    }
    return { modules: modules, functions: functions };
  }

  function setFunction(matrix, x, y, value) {
    matrix.modules[y][x] = value;
    matrix.functions[y][x] = true;
  }

  function drawFinderPattern(matrix, centerX, centerY) {
    for (var dy = -4; dy <= 4; dy += 1) {
      for (var dx = -4; dx <= 4; dx += 1) {
        var x = centerX + dx;
        var y = centerY + dy;
        if (x < 0 || x >= SIZE || y < 0 || y >= SIZE) {
          continue;
        }

        var distance = Math.max(Math.abs(dx), Math.abs(dy));
        setFunction(matrix, x, y, distance !== 2 && distance !== 4);
      }
    }
  }

  function drawAlignmentPattern(matrix, centerX, centerY) {
    for (var dy = -2; dy <= 2; dy += 1) {
      for (var dx = -2; dx <= 2; dx += 1) {
        var distance = Math.max(Math.abs(dx), Math.abs(dy));
        setFunction(matrix, centerX + dx, centerY + dy, distance !== 1);
      }
    }
  }

  function reserveFormatAreas(matrix) {
    for (var i = 0; i <= 8; i += 1) {
      if (i !== 6) {
        setFunction(matrix, 8, i, false);
        setFunction(matrix, i, 8, false);
      }
    }

    for (var j = 0; j < 8; j += 1) {
      setFunction(matrix, SIZE - 1 - j, 8, false);
      if (j < 7) {
        setFunction(matrix, 8, SIZE - 1 - j, false);
      }
    }
  }

  function drawVersionAreas(matrix) {
    if (VERSION < 7) {
      return;
    }

    for (var i = 0; i < 6; i += 1) {
      for (var j = 0; j < 3; j += 1) {
        setFunction(matrix, SIZE - 11 + j, i, false);
        setFunction(matrix, i, SIZE - 11 + j, false);
      }
    }
  }

  function drawFunctionPatterns(matrix) {
    for (var i = 0; i < SIZE; i += 1) {
      setFunction(matrix, 6, i, i % 2 === 0);
      setFunction(matrix, i, 6, i % 2 === 0);
    }

    drawFinderPattern(matrix, 3, 3);
    drawFinderPattern(matrix, SIZE - 4, 3);
    drawFinderPattern(matrix, 3, SIZE - 4);

    for (var yIndex = 0; yIndex < ALIGNMENT_POSITIONS.length; yIndex += 1) {
      for (var xIndex = 0; xIndex < ALIGNMENT_POSITIONS.length; xIndex += 1) {
        var ax = ALIGNMENT_POSITIONS[xIndex];
        var ay = ALIGNMENT_POSITIONS[yIndex];
        var overlapsFinder =
          (xIndex === 0 && yIndex === 0) ||
          (xIndex === ALIGNMENT_POSITIONS.length - 1 && yIndex === 0) ||
          (xIndex === 0 && yIndex === ALIGNMENT_POSITIONS.length - 1);

        if (!overlapsFinder) {
          drawAlignmentPattern(matrix, ax, ay);
        }
      }
    }

    reserveFormatAreas(matrix);
    drawVersionAreas(matrix);
    setFunction(matrix, 8, SIZE - 8, true);
  }

  function getBit(value, index) {
    return ((value >>> index) & 1) !== 0;
  }

  function drawFormatBits(matrix) {
    var errorCorrectionFormatBits = 1; // Level L
    var mask = 0;
    var data = (errorCorrectionFormatBits << 3) | mask;
    var remainder = data;

    for (var i = 0; i < 10; i += 1) {
      remainder = (remainder << 1) ^ (((remainder >>> 9) & 1) * 0x537);
    }

    var bits = ((data << 10) | remainder) ^ 0x5412;

    for (var a = 0; a <= 5; a += 1) {
      setFunction(matrix, 8, a, getBit(bits, a));
    }
    setFunction(matrix, 8, 7, getBit(bits, 6));
    setFunction(matrix, 8, 8, getBit(bits, 7));
    setFunction(matrix, 7, 8, getBit(bits, 8));
    for (var b = 9; b < 15; b += 1) {
      setFunction(matrix, 14 - b, 8, getBit(bits, b));
    }

    for (var c = 0; c < 8; c += 1) {
      setFunction(matrix, SIZE - 1 - c, 8, getBit(bits, c));
    }
    for (var d = 8; d < 15; d += 1) {
      setFunction(matrix, 8, SIZE - 15 + d, getBit(bits, d));
    }
    setFunction(matrix, 8, SIZE - 8, true);
  }

  function drawVersionBits(matrix) {
    if (VERSION < 7) {
      return;
    }

    var remainder = VERSION;
    for (var i = 0; i < 12; i += 1) {
      remainder = (remainder << 1) ^ (((remainder >>> 11) & 1) * 0x1f25);
    }

    var bits = (VERSION << 12) | remainder;
    for (var j = 0; j < 18; j += 1) {
      var bit = getBit(bits, j);
      var a = SIZE - 11 + (j % 3);
      var b = Math.floor(j / 3);
      setFunction(matrix, a, b, bit);
      setFunction(matrix, b, a, bit);
    }
  }

  function placeCodewords(matrix, codewords) {
    var bitLength = codewords.length * 8;
    var bitIndex = 0;
    var upward = true;

    for (var right = SIZE - 1; right >= 1; right -= 2) {
      if (right === 6) {
        right -= 1;
      }

      for (var i = 0; i < SIZE; i += 1) {
        var y = upward ? SIZE - 1 - i : i;
        for (var j = 0; j < 2; j += 1) {
          var x = right - j;
          if (matrix.functions[y][x]) {
            continue;
          }

          var isDark = false;
          if (bitIndex < bitLength) {
            var codeword = codewords[bitIndex >>> 3];
            isDark = ((codeword >>> (7 - (bitIndex & 7))) & 1) !== 0;
          }
          matrix.modules[y][x] = isDark;
          bitIndex += 1;
        }
      }

      upward = !upward;
    }
  }

  function applyMask(matrix) {
    for (var y = 0; y < SIZE; y += 1) {
      for (var x = 0; x < SIZE; x += 1) {
        if (!matrix.functions[y][x] && (x + y) % 2 === 0) {
          matrix.modules[y][x] = !matrix.modules[y][x];
        }
      }
    }
  }

  function renderSvg(matrix) {
    var dimension = SIZE + (QUIET_ZONE * 2);
    var parts = [
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + dimension + ' ' + dimension + '" role="img" aria-label="QR code TOTP">',
      '<rect width="100%" height="100%" fill="#ffffff"/>'
    ];

    for (var y = 0; y < SIZE; y += 1) {
      for (var x = 0; x < SIZE; x += 1) {
        if (matrix.modules[y][x]) {
          parts.push('<rect x="' + (x + QUIET_ZONE) + '" y="' + (y + QUIET_ZONE) + '" width="1" height="1" fill="#0f172a"/>');
        }
      }
    }

    parts.push('</svg>');
    return parts.join('');
  }

  function buildQrSvg(value) {
    var dataCodewords = buildCodewords(value);
    var allCodewords = interleaveWithEcc(dataCodewords);
    var matrix = createMatrix();

    drawFunctionPatterns(matrix);
    placeCodewords(matrix, allCodewords);
    applyMask(matrix);
    drawFormatBits(matrix);
    drawVersionBits(matrix);

    return renderSvg(matrix);
  }

  function mountQrCode(node) {
    var value = node.getAttribute('data-qr-value') || '';
    var errorNode = node.parentElement ? node.parentElement.querySelector('[data-qr-error]') : null;

    if (!value) {
      if (errorNode) {
        errorNode.hidden = false;
      }
      return;
    }

    try {
      node.innerHTML = buildQrSvg(value);
      node.classList.add('is-ready');
      if (errorNode) {
        errorNode.hidden = true;
      }
    } catch (error) {
      console.error(error);
      if (errorNode) {
        errorNode.hidden = false;
      }
    }
  }

  window.initTotpQrCodes = function initTotpQrCodes() {
    document.querySelectorAll('[data-totp-qr]').forEach(mountQrCode);
  };
})();

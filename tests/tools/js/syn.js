import * as std from 'std';
const src = std.loadFile(scriptArgs[1]);
try { new Function(src); print('OK'); } catch (e) { print('ERR ' + e + (e.lineNumber ? ' line ' + e.lineNumber : '')); }

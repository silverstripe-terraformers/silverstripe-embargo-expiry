const Path = require('path');
const { JavascriptWebpackConfig } = require('@silverstripe/webpack-config');

const PATHS = {
  ROOT: Path.resolve(),
  SRC: Path.resolve('client/src'),
};

module.exports = [
  new JavascriptWebpackConfig('js', PATHS)
    .setEntry({
      bundle: `${PATHS.SRC}/js/bundle.js`,
    })
    .getConfig(),
];

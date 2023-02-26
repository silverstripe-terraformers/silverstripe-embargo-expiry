const Path = require('path');
const webpack = require('webpack');
const { JavascriptWebpackConfig, CssWebpackConfig } = require('@silverstripe/webpack-config');

const PATHS = {
  ROOT: Path.resolve(),
  SRC: Path.resolve(),
};

const config = [
  new JavascriptWebpackConfig('js', { ...PATHS, DIST: `${PATHS.ROOT}/client/dist/js/` })
    .setEntry({
      main: 'client/src/bundles/embargo-expiry.js'
    })
    // Output the javascript with a different filename schema than the default
    .mergeConfig({
      output: {
        filename: 'embargo-expiry.js',
      },
    })
    .getConfig(),
];

module.exports = config;

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
    // Output the css with a different filename schema than the default
    .mergeConfig({
      output: {
        filename: 'embargo-expiry.js',
      },
    })
    .getConfig(),
  new CssWebpackConfig('css', { ...PATHS, DIST: `${PATHS.ROOT}/client/dist/css/` })
    .setEntry({
      main: './client/src/styles/embargo.css'
    })
    // Output the css with a different filename schema than the default
    .mergeConfig({
      output: {
        filename: 'embargo.css',
      },
    })
    .getConfig(),
];

module.exports = config;

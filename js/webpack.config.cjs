const config = require("flarum-webpack-config");

const webpackConfig = config();

webpackConfig.output.clean = false;

module.exports = webpackConfig;

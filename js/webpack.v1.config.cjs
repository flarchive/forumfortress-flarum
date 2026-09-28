const config = require("flarum-webpack-config-v1");

const webpackConfig = config();

webpackConfig.output.clean = false;
webpackConfig.output.filename = "[name].v1.js";

module.exports = webpackConfig;

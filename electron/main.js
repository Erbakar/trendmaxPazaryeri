'use strict';
const { app, BrowserWindow, Menu, dialog, ipcMain } = require('electron');
const path = require('path');
const fs = require('fs');
const { pathToFileURL } = require('url');
const { validateServerUrl } = require('./server-url');
let mainWindow = null;
let serverOrigin = null;
const connectPath = path.join(__dirname, 'connect.html');
const connectUrl = pathToFileURL(connectPath).href;
const settingsPath = () => path.join(app.getPath('userData'), 'server.json');
function savedUrl() {
    try { return validateServerUrl(JSON.parse(fs.readFileSync(settingsPath(), 'utf8')).url); }
    catch { return ''; }
}
function verifySender(event) {
    if (event.sender !== mainWindow?.webContents || event.senderFrame?.url !== connectUrl) {
        throw new Error('Yetkisiz bağlantı isteği');
    }
}
async function connect(value) {
    const url = validateServerUrl(value);
    serverOrigin = new URL(url).origin;
    try { await mainWindow.loadURL(url); }
    catch (error) { await mainWindow.loadFile(connectPath); throw error; }
    fs.mkdirSync(app.getPath('userData'), { recursive: true });
    fs.writeFileSync(settingsPath(), JSON.stringify({ url }), { mode: 0o600 });
}
function createWindow() {
    mainWindow = new BrowserWindow({ width: 1280, height: 800, minWidth: 800, minHeight: 600,
        title: 'Trendmax', webPreferences: { preload: path.join(__dirname, 'preload.js'),
            nodeIntegration: false, contextIsolation: true, sandbox: true } });
    mainWindow.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
    mainWindow.webContents.on('will-navigate', (event, url) => {
        if (url !== connectUrl && (!serverOrigin || new URL(url).origin !== serverOrigin)) event.preventDefault();
    });
    mainWindow.webContents.session.setPermissionRequestHandler((_contents, _permission, callback) => callback(false));
    const url = savedUrl();
    if (url) connect(url).catch(() => {});
    else mainWindow.loadFile(connectPath);
    mainWindow.on('closed', () => { mainWindow = null; });
}
app.whenReady().then(() => {
    ipcMain.handle('server:get', event => { verifySender(event); return savedUrl(); });
    ipcMain.handle('server:connect', (event, value) => { verifySender(event); return connect(value); });
    Menu.setApplicationMenu(Menu.buildFromTemplate([
        { label: 'Dosya', submenu: [
            { label: 'Sunucu Değiştir', click: () => mainWindow?.loadFile(connectPath) },
            { role: 'quit', label: 'Çıkış' } ] },
        { label: 'Görünüm', submenu: [{ role: 'reload' }, { role: 'togglefullscreen' }] },
        { label: 'Yardım', submenu: [{ label: 'Hakkında', click: () => dialog.showMessageBox({
            message: `Trendmax ${app.getVersion()}`, type: 'info' }) }] },
    ]));
    createWindow();
});
app.on('window-all-closed', () => { if (process.platform !== 'darwin') app.quit(); });
app.on('activate', () => { if (!mainWindow) createWindow(); });

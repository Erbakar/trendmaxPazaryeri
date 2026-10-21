/**
 * OzyConnect Panel — Electron Preload Script (İskelet)
 * © 2025 OziyWeb — Tüm Hakları Saklıdır.
 *
 * Context bridge — web ve electron arası güvenli köprü.
 */

const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('OzyConnect', {
    platform: process.platform,
    version: '1.0.0',
    developer: 'OziyWeb',
    // İleride eklenecek:
    // sendNotification: (title, body) => ipcRenderer.send('notify', { title, body }),
    // onNewOrder: (callback) => ipcRenderer.on('new-order', callback),
});

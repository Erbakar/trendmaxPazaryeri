'use strict';
const { contextBridge, ipcRenderer } = require('electron');
// Connection controls are exposed only to the bundled page, never a remote server.
if (location.protocol === 'file:') {
    contextBridge.exposeInMainWorld('Trendmax', {
        getServerUrl: () => ipcRenderer.invoke('server:get'),
        connect: value => ipcRenderer.invoke('server:connect', value),
    });
}

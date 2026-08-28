/**
 * 100% Self-Hosted WhatsApp Web Engine Server (Node.js + Baileys)
 * Marg ERP 9+ CRM & Invoice Dispatching
 */

const express = require('express');
const QRCode = require('qrcode');
const cors = require('cors');
const { makeWASocket, useMultiFileAuthState, DisconnectReason, Browsers } = require('@whiskeysockets/baileys');

const app = express();
app.use(express.json());
app.use(cors());

const PORT = process.env.PORT || 3005;
let qrCodeData = '';
let connectionStatus = 'disconnected'; // 'connected', 'scan_qr', 'disconnected'
let pairedPhone = '';
let sock = null;

async function connectToWhatsApp() {
    try {
        const { state, saveCreds } = await useMultiFileAuthState('auth_info_baileys');
        
        sock = makeWASocket({
            auth: state,
            browser: Browsers.ubuntu("Chrome")
        });

        sock.ev.on('creds.update', saveCreds);

        sock.ev.on('connection.update', async (update) => {
            const { connection, lastDisconnect, qr } = update;

            if (qr) {
                qrCodeData = qr;
                connectionStatus = 'scan_qr';
                console.log('New WhatsApp Web QR Code generated for pairing!');
            }

            if (connection === 'close') {
                const statusCode = lastDisconnect?.error?.output?.statusCode;
                const isLoggedOut = (statusCode === DisconnectReason.loggedOut || statusCode === 401);
                console.log('Connection closed due to ', lastDisconnect?.error, ', statusCode:', statusCode);
                connectionStatus = 'disconnected';
                
                if (isLoggedOut) {
                    console.log('⚠️ Session logged out (401). Clearing auth_info_baileys for fresh QR code generation...');
                    const fs = require('fs');
                    try { fs.rmSync('auth_info_baileys', { recursive: true, force: true }); } catch(e){}
                    qrCodeData = '';
                    setTimeout(connectToWhatsApp, 2000);
                } else {
                    setTimeout(connectToWhatsApp, 3000);
                }
            } else if (connection === 'open') {
                console.log('🎉 SUCCESS: WhatsApp Web Connected & Authenticated Successfully!');
                connectionStatus = 'connected';
                qrCodeData = '';
                if (sock.user && sock.user.id) {
                    pairedPhone = sock.user.id.split(':')[0];
                }
            }
        });
    } catch (err) {
        console.error('Error starting Baileys engine:', err);
    }
}

// Start engine
connectToWhatsApp();

// API Endpoints
app.get('/qr', async (req, res) => {
    if (connectionStatus === 'connected') {
        return res.json({ status: 'connected', message: 'Phone is already paired & connected!', phone: pairedPhone });
    }

    if (qrCodeData) {
        try {
            const qrImageBase64 = await QRCode.toDataURL(qrCodeData);
            return res.json({
                status: 'scan_qr',
                qr: qrCodeData,
                qr_image: qrImageBase64
            });
        } catch (e) {
            return res.json({ status: 'scan_qr', qr: qrCodeData });
        }
    }

    return res.json({ status: 'initializing', message: 'Generating QR code... Please try again in 3 seconds.' });
});

app.get('/status', (req, res) => {
    res.json({
        status: connectionStatus,
        phone_number: pairedPhone,
        engine: 'Self-Hosted Baileys Engine v1.0',
        uptime: process.uptime()
    });
});

app.post('/pairing-code', async (req, res) => {
    const { phone } = req.body;
    if (!phone) {
        return res.status(400).json({ status: 'error', message: 'Phone number required.' });
    }
    const cleanPhone = phone.replace(/\D/g, '');
    try {
        if (sock && !sock.authState.creds.registered) {
            const code = await sock.requestPairingCode(cleanPhone);
            return res.json({ status: 'success', pairing_code: code });
        } else if (connectionStatus === 'connected') {
            return res.json({ status: 'connected', message: 'Already connected!', phone: pairedPhone });
        } else {
            return res.status(400).json({ status: 'error', message: 'Engine initializing, please retry.' });
        }
    } catch (e) {
        return res.status(500).json({ status: 'error', message: e.message });
    }
});

app.post('/send-message', async (req, res) => {
    const { recipient, message, pdf_url } = req.body;
    if (!recipient || !message) {
        return res.status(400).json({ status: 'error', message: 'Recipient and message required.' });
    }

    let jid = recipient.replace(/\D/g, '') + '@s.whatsapp.net';

    try {
        console.log(`Attempting to send message to ${jid}, connectionStatus: ${connectionStatus}`);
        if (sock && connectionStatus === 'connected') {
            let result;
            if (pdf_url) {
                result = await sock.sendMessage(jid, {
                    document: { url: pdf_url },
                    mimetype: 'application/pdf',
                    fileName: 'Invoice.pdf',
                    caption: message
                });
            } else {
                result = await sock.sendMessage(jid, { text: message });
            }
            console.log(`Message sent successfully to ${jid}, ID: ${result?.key?.id}`);
            return res.json({ status: 'success', success: true, message_id: result?.key?.id });
        } else {
            console.log(`Failed to send message: connectionStatus is ${connectionStatus}`);
            return res.status(503).json({ status: 'error', message: `WhatsApp Web Session is currently ${connectionStatus}. Please wait 10 seconds for initial sync to finish.` });
        }
    } catch (err) {
        console.error(`Error sending message to ${jid}:`, err);
        return res.status(500).json({ status: 'error', message: err.message });
    }
});

app.post('/logout', async (req, res) => {
    try {
        if (sock) {
            await sock.logout();
        }
        connectionStatus = 'disconnected';
        pairedPhone = '';
        qrCodeData = '';
        res.json({ status: 'success', message: 'Logged out successfully.' });
    } catch (err) {
        res.json({ status: 'error', message: err.message });
    }
});

app.listen(PORT, () => {
    console.log(`Self-Hosted WhatsApp Web Engine running on port ${PORT}`);
});

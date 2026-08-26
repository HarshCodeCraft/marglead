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

const PORT = process.env.PORT || 3000;
let qrCodeData = '';
let connectionStatus = 'disconnected'; // 'connected', 'scan_qr', 'disconnected'
let pairedPhone = '';
let sock = null;

async function connectToWhatsApp() {
    try {
        const { state, saveCreds } = await useMultiFileAuthState('auth_info_baileys');
        
        sock = makeWASocket({
            auth: state,
            printQRInTerminal: true,
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
                const shouldReconnect = (lastDisconnect?.error?.output?.statusCode !== DisconnectReason.loggedOut);
                console.log('Connection closed due to ', lastDisconnect?.error, ', reconnecting: ', shouldReconnect);
                connectionStatus = 'disconnected';
                if (shouldReconnect) {
                    connectToWhatsApp();
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

app.post('/send-message', async (req, res) => {
    const { recipient, message, pdf_url } = req.body;
    if (!recipient || !message) {
        return res.status(400).json({ status: 'error', message: 'Recipient and message required.' });
    }

    let jid = recipient.replace(/\D/g, '') + '@s.whatsapp.net';

    try {
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

            return res.json({ status: 'success', success: true, message_id: result.key.id });
        } else {
            return res.status(503).json({ status: 'error', message: 'WhatsApp Web Session not connected. Scan QR code first.' });
        }
    } catch (err) {
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

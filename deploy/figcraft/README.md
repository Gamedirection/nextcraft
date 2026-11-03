# FigCraft live sessions for NextCraft

1. Edit `server.toml` (`public_url` = `wss://<your nextcloud host>/nextcraft-live/`).
2. `TAG=v0.14.0 NEXTCLOUD_NETWORK=<network of your Nextcloud web server> docker compose up -d --build`
3. Add `apache.conf` (Apache) or `nginx.conf` (nginx) to your Nextcloud web server and reload it.
4. Administration settings ▸ NextCraft ▸ Live sessions: `wss://<your nextcloud host>/nextcraft-live/`.
5. In FigCraft: Share ▸ Online ▸ Start sharing, then send the join code. With `open_publish = false`,
   publishing needs the server key: `docker exec figcraft-server figcraft-server --data /data key`.

Update: change `TAG` and run step 2 again.

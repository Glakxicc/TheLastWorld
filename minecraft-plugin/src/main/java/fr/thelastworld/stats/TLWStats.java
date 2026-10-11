package fr.thelastworld.stats;

import org.bukkit.Bukkit;
import org.bukkit.Statistic;
import org.bukkit.entity.Player;
import org.bukkit.event.EventHandler;
import org.bukkit.event.EventPriority;
import org.bukkit.event.Listener;
import org.bukkit.event.player.PlayerJoinEvent;
import org.bukkit.event.player.PlayerQuitEvent;
import org.bukkit.plugin.java.JavaPlugin;

import javax.net.ssl.SSLException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Collection;
import java.util.Collections;
import java.util.List;
import java.util.Scanner;
import java.util.UUID;
import java.util.logging.Level;
import java.util.regex.Pattern;

/**
 * Envoie au site les statistiques des joueurs (temps de jeu, morts, kills, dernière connexion)
 * et la liste des joueurs connectés : à la connexion, à la déconnexion et à intervalle régulier.
 *
 * Prévu pour Mohist 1.12.2 (Forge + Bukkit, Java 8), fonctionne aussi sur Spigot/Paper 1.12.2+.
 */
public final class TLWStats extends JavaPlugin implements Listener {

    /** Pseudo Minecraft valide : écarte les faux joueurs des mods Forge ([Minecraft], [ComputerCraft]...). */
    private static final Pattern PLAYER_NAME = Pattern.compile("^[A-Za-z0-9_]{1,16}$");

    private URL endpoint;
    private String token;
    private Statistic playTime;

    @Override
    public void onEnable() {
        saveDefaultConfig();
        token = getConfig().getString("token", "").trim();
        String url = getConfig().getString("url", "").trim();

        if (token.isEmpty() || url.isEmpty()) {
            getLogger().warning("Renseignez url et token dans plugins/TLWStats/config.yml puis redémarrez le serveur.");
            return;
        }

        try {
            endpoint = new URL(url);
        } catch (Exception error) {
            getLogger().warning("Adresse invalide dans config.yml : " + url);
            return;
        }

        // PLAY_ONE_TICK en 1.12, renommée PLAY_ONE_MINUTE à partir de 1.13 (compte toujours des ticks)
        playTime = statistic("PLAY_ONE_TICK", "PLAY_ONE_MINUTE");
        getServer().getPluginManager().registerEvents(this, this);

        long intervalTicks = Math.max(60, getConfig().getLong("interval-seconds", 300)) * 20L;
        Bukkit.getScheduler().runTaskTimer(this, () -> {
            List<Player> online = realPlayers(Bukkit.getOnlinePlayers(), null);
            sendAsync(payload(online, uuids(online)));
        }, 20L * 10, intervalTicks);

        getLogger().info("Statistiques envoyées à " + url);
    }

    @Override
    public void onDisable() {
        if (endpoint == null) {
            return;
        }
        // Arrêt du serveur : dernières statistiques, et tout le monde passe hors ligne.
        // Envoi bloquant car le plugin s'arrête juste après.
        try {
            send(payload(realPlayers(Bukkit.getOnlinePlayers(), null), Collections.<UUID>emptyList()));
        } catch (Exception error) {
            warnSendFailure("Envoi des statistiques à l'arrêt impossible", error);
        }
    }

    @EventHandler(priority = EventPriority.MONITOR)
    public void onJoin(PlayerJoinEvent event) {
        if (!isRealPlayer(event.getPlayer())) {
            return;
        }
        sendAsync(payload(Collections.singletonList(event.getPlayer()),
            uuids(realPlayers(Bukkit.getOnlinePlayers(), null))));
    }

    @EventHandler(priority = EventPriority.MONITOR)
    public void onQuit(PlayerQuitEvent event) {
        Player player = event.getPlayer();
        if (!isRealPlayer(player)) {
            return;
        }
        // Le joueur fait encore partie des connectés pendant cet événement : on l'exclut
        sendAsync(payload(Collections.singletonList(player),
            uuids(realPlayers(Bukkit.getOnlinePlayers(), player.getUniqueId()))));
    }

    // --- Joueurs ---

    private static boolean isRealPlayer(Player player) {
        return player != null && player.getUniqueId() != null && player.getName() != null
            && PLAYER_NAME.matcher(player.getName()).matches();
    }

    private static List<Player> realPlayers(Collection<? extends Player> players, UUID except) {
        List<Player> result = new ArrayList<>();
        for (Player player : players) {
            if (isRealPlayer(player) && !player.getUniqueId().equals(except)) {
                result.add(player);
            }
        }
        return result;
    }

    private static List<UUID> uuids(Collection<Player> players) {
        List<UUID> result = new ArrayList<>();
        for (Player player : players) {
            result.add(player.getUniqueId());
        }
        return result;
    }

    // --- Envoi ---

    private void sendAsync(String json) {
        Bukkit.getScheduler().runTaskAsynchronously(this, () -> {
            try {
                send(json);
            } catch (Exception error) {
                warnSendFailure("Site injoignable", error);
            }
        });
    }

    private void send(String json) throws Exception {
        byte[] body = json.getBytes(StandardCharsets.UTF_8);
        HttpURLConnection connection = (HttpURLConnection) endpoint.openConnection();
        try {
            connection.setConnectTimeout(5000);
            connection.setReadTimeout(10000);
            connection.setUseCaches(false);
            connection.setRequestMethod("POST");
            connection.setDoOutput(true);
            connection.setFixedLengthStreamingMode(body.length);
            connection.setRequestProperty("Content-Type", "application/json; charset=utf-8");
            connection.setRequestProperty("Accept", "application/json");
            // Sans User-Agent explicite, certains hébergeurs bloquent « Java/1.8.0_xxx »
            connection.setRequestProperty("User-Agent", "TLWStats/" + getDescription().getVersion());
            connection.setRequestProperty("X-TLW-Token", token);

            try (OutputStream out = connection.getOutputStream()) {
                out.write(body);
            }

            int status = connection.getResponseCode();
            if (status != 200) {
                getLogger().warning("Le site a refusé les statistiques (" + status + ") : " + read(connection.getErrorStream()));
            } else {
                read(connection.getInputStream());
            }
        } finally {
            connection.disconnect();
        }
    }

    private void warnSendFailure(String message, Exception error) {
        if (error instanceof SSLException) {
            // Les vieilles versions de Java 8 (avant 8u101) ne reconnaissent pas les certificats Let's Encrypt
            getLogger().warning(message + " : connexion HTTPS refusée (" + error.getMessage() + "). "
                + "Mettez à jour Java 8 (8u101 minimum, idéalement la dernière 8u) sur le serveur.");
        } else {
            getLogger().log(Level.WARNING, message + " : " + error);
        }
    }

    private static String read(InputStream in) {
        if (in == null) {
            return "";
        }
        try (Scanner scanner = new Scanner(in, "UTF-8").useDelimiter("\\A")) {
            return scanner.hasNext() ? scanner.next() : "";
        }
    }

    // --- JSON ---

    /** Statistiques des joueurs donnés + liste complète des connectés. Lu sur le thread principal. */
    private String payload(Collection<? extends Player> players, Collection<UUID> online) {
        long now = System.currentTimeMillis();
        StringBuilder json = new StringBuilder("{\"players\":[");

        String separator = "";
        for (Player player : players) {
            json.append(separator)
                .append("{\"uuid\":\"").append(player.getUniqueId()).append('"')
                .append(",\"name\":\"").append(player.getName()).append('"')
                // Compte des ticks (20 par seconde)
                .append(",\"playtime\":").append(stat(player, playTime) / 20)
                .append(",\"deaths\":").append(stat(player, Statistic.DEATHS))
                .append(",\"mobKills\":").append(stat(player, Statistic.MOB_KILLS))
                .append(",\"playerKills\":").append(stat(player, Statistic.PLAYER_KILLS))
                .append(",\"lastSeen\":").append(now)
                .append('}');
            separator = ",";
        }

        json.append("],\"online\":[");
        separator = "";
        for (UUID uuid : online) {
            json.append(separator).append('"').append(uuid).append('"');
            separator = ",";
        }
        return json.append("]}").toString();
    }

    /** Lecture protégée : sur un serveur hybride Forge, une statistique peut manquer ou lever une erreur. */
    private int stat(Player player, Statistic statistic) {
        if (statistic == null) {
            return 0;
        }
        try {
            return Math.max(0, player.getStatistic(statistic));
        } catch (Throwable error) {
            getLogger().fine("Statistique " + statistic + " illisible pour " + player.getName() + " : " + error);
            return 0;
        }
    }

    /** Première statistique existante parmi les noms donnés (les noms changent selon la version). */
    private static Statistic statistic(String... names) {
        for (String name : names) {
            try {
                return Statistic.valueOf(name);
            } catch (IllegalArgumentException ignored) {
                // Absente de cette version
            }
        }
        return null;
    }
}

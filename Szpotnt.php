<?php
/**
 * Minecraft Death Counter & Stats Plugin
 * Zaawansowany system statystyk graczy
 * Autor: Skrypt PHP do Minecrafta
 */

class MinecraftStatsPlugin {
    private $db;
    private $statsFile = 'minecraft_stats.json';
    private $playersStats = [];
    private $useDatabase = false;
    private $dbConfig = [
        'host' => 's6.crafthost.pl',
        'user' => 'root',
        'password' => 'Kasztelan1',  // Zmień na rzeczywiste hasło
        'database' => 'svr102872',
        'port' => 3306
    ];
    
    public function __construct($useDatabase = false, $dbConfig = null) {
        $this->useDatabase = $useDatabase;
        
        if ($dbConfig) {
            $this->dbConfig = array_merge($this->dbConfig, $dbConfig);
        }
        
        if ($this->useDatabase) {
            $this->initializeDatabaseConnection();
            $this->createDatabaseTables();
        } else {
            $this->initializeJsonDatabase();
        }
        
        $this->loadStats();
    }
    
    /**
     * Inicjalizuj połączenie z bazą danych
     */
    private function initializeDatabaseConnection() {
        try {
            $this->db = new mysqli(
                $this->dbConfig['host'],
                $this->dbConfig['user'],
                $this->dbConfig['password'],
                $this->dbConfig['database'],
                $this->dbConfig['port']
            );
            
            if ($this->db->connect_error) {
                throw new Exception("Błąd połączenia: " . $this->db->connect_error);
            }
            
            $this->db->set_charset("utf8mb4");
        } catch (Exception $e) {
            die("Nie udało się połączyć z bazą danych: " . $e->getMessage());
        }
    }
    
    /**
     * Utwórz tabele w bazie danych
     */
    private function createDatabaseTables() {
        $queries = [
            "CREATE TABLE IF NOT EXISTS players (
                player_id INT AUTO_INCREMENT PRIMARY KEY,
                player_name VARCHAR(16) UNIQUE NOT NULL,
                deaths INT DEFAULT 0,
                kills INT DEFAULT 0,
                total_play_time INT DEFAULT 0,
                best_streak INT DEFAULT 0,
                current_streak INT DEFAULT 0,
                logins INT DEFAULT 0,
                first_join DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_login DATETIME,
                last_logout DATETIME,
                last_death DATETIME,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX(player_name),
                INDEX(deaths),
                INDEX(kills),
                INDEX(total_play_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
            
            "CREATE TABLE IF NOT EXISTS death_logs (
                death_id INT AUTO_INCREMENT PRIMARY KEY,
                player_id INT NOT NULL,
                killer_id INT,
                death_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (player_id) REFERENCES players(player_id) ON DELETE CASCADE,
                FOREIGN KEY (killer_id) REFERENCES players(player_id) ON DELETE SET NULL,
                INDEX(player_id),
                INDEX(killer_id),
                INDEX(death_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
            
            "CREATE TABLE IF NOT EXISTS session_logs (
                session_id INT AUTO_INCREMENT PRIMARY KEY,
                player_id INT NOT NULL,
                login_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                logout_time DATETIME,
                play_duration INT,
                FOREIGN KEY (player_id) REFERENCES players(player_id) ON DELETE CASCADE,
                INDEX(player_id),
                INDEX(login_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
        ];
        
        foreach ($queries as $query) {
            if (!$this->db->query($query)) {
                die("Błąd utworzenia tabeli: " . $this->db->error);
            }
        }
    }
    
    /**
     * Inicjalizuj bazę JSON
     */
    private function initializeJsonDatabase() {
        if (!file_exists($this->statsFile)) {
            file_put_contents($this->statsFile, json_encode([]));
        }
    }
    
    /**
     * Pobierz lub utwórz gracza w bazie
     */
    private function getOrCreatePlayer($playerName) {
        if (!$this->useDatabase) {
            return null;
        }
        
        $stmt = $this->db->prepare("SELECT player_id FROM players WHERE player_name = ?");
        $stmt->bind_param("s", $playerName);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['player_id'];
        }
        
        $stmt = $this->db->prepare("INSERT INTO players (player_name) VALUES (?)");
        $stmt->bind_param("s", $playerName);
        $stmt->execute();
        return $this->db->insert_id;
    }
    
    /**
     * Załaduj statystyki z pliku
     */
    private function loadStats() {
        if (file_exists($this->statsFile)) {
            $this->playersStats = json_decode(file_get_contents($this->statsFile), true) ?: [];
        }
    }
    
    /**
     * Zapisz statystyki do pliku
     */
    private function saveStats() {
        file_put_contents($this->statsFile, json_encode($this->playersStats, JSON_PRETTY_PRINT));
    }
    
    /**
     * Zanotuj śmierć gracza
     */
    public function recordDeath($playerName, $killer = null) {
        if ($this->useDatabase) {
            return $this->recordDeathDatabase($playerName, $killer);
        }
        
        if (!isset($this->playersStats[$playerName])) {
            $this->playersStats[$playerName] = $this->initializePlayerStats($playerName);
        }
        
        $player = &$this->playersStats[$playerName];
        $player['deaths']++;
        $player['lastDeath'] = date('Y-m-d H:i:s');
        $player['currentStreak'] = 0;
        
        if ($killer && $killer !== $playerName) {
            if (!isset($this->playersStats[$killer])) {
                $this->playersStats[$killer] = $this->initializePlayerStats($killer);
            }
            $this->playersStats[$killer]['kills']++;
        }
        
        $this->saveStats();
        return $player;
    }
    
    /**
     * Zanotuj śmierć w bazie danych
     */
    private function recordDeathDatabase($playerName, $killer = null) {
        $playerId = $this->getOrCreatePlayer($playerName);
        $killerId = $killer ? $this->getOrCreatePlayer($killer) : null;
        
        $stmt = $this->db->prepare("UPDATE players SET deaths = deaths + 1, last_death = NOW() WHERE player_id = ?");
        $stmt->bind_param("i", $playerId);
        $stmt->execute();
        
        if ($killer && $killer !== $playerName) {
            $stmt = $this->db->prepare("UPDATE players SET kills = kills + 1 WHERE player_id = ?");
            $stmt->bind_param("i", $killerId);
            $stmt->execute();
        }
        
        $stmt = $this->db->prepare("INSERT INTO death_logs (player_id, killer_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $playerId, $killerId);
        $stmt->execute();
        
        return $this->getPlayerStats($playerName);
    }
    
    /**
     * Dodaj czas w grze
     */
    public function addPlayTime($playerName, $minutes) {
        if ($this->useDatabase) {
            return $this->addPlayTimeDatabase($playerName, $minutes);
        }
        
        if (!isset($this->playersStats[$playerName])) {
            $this->playersStats[$playerName] = $this->initializePlayerStats($playerName);
        }
        
        $this->playersStats[$playerName]['totalPlayTime'] += $minutes;
        $this->playersStats[$playerName]['currentSessionStart'] = time();
        
        $this->saveStats();
        return $this->playersStats[$playerName];
    }
    
    /**
     * Dodaj czas w grze do bazy danych
     */
    private function addPlayTimeDatabase($playerName, $minutes) {
        $playerId = $this->getOrCreatePlayer($playerName);
        
        $stmt = $this->db->prepare("UPDATE players SET total_play_time = total_play_time + ? WHERE player_id = ?");
        $stmt->bind_param("ii", $minutes, $playerId);
        $stmt->execute();
        
        return $this->getPlayerStats($playerName);
    }
    
    /**
     * Zapisz login gracza
     */
    public function recordLogin($playerName) {
        if ($this->useDatabase) {
            return $this->recordLoginDatabase($playerName);
        }
        
        if (!isset($this->playersStats[$playerName])) {
            $this->playersStats[$playerName] = $this->initializePlayerStats($playerName);
        }
        
        $this->playersStats[$playerName]['lastLogin'] = date('Y-m-d H:i:s');
        $this->playersStats[$playerName]['currentSessionStart'] = time();
        $this->playersStats[$playerName]['logins']++;
        
        $this->saveStats();
        return $this->playersStats[$playerName];
    }
    
    /**
     * Zapisz login do bazy danych
     */
    private function recordLoginDatabase($playerName) {
        $playerId = $this->getOrCreatePlayer($playerName);
        
        $stmt = $this->db->prepare("UPDATE players SET last_login = NOW(), logins = logins + 1 WHERE player_id = ?");
        $stmt->bind_param("i", $playerId);
        $stmt->execute();
        
        $stmt = $this->db->prepare("INSERT INTO session_logs (player_id, login_time) VALUES (?, NOW())");
        $stmt->bind_param("i", $playerId);
        $stmt->execute();
        
        return $this->getPlayerStats($playerName);
    }
    
    /**
     * Zapisz logout gracza
     */
    public function recordLogout($playerName) {
        if ($this->useDatabase) {
            return $this->recordLogoutDatabase($playerName);
        }
        
        if (!isset($this->playersStats[$playerName])) {
            return false;
        }
        
        $sessionStart = $this->playersStats[$playerName]['currentSessionStart'] ?? time();
        $sessionMinutes = (time() - $sessionStart) / 60;
        
        $this->playersStats[$playerName]['lastLogout'] = date('Y-m-d H:i:s');
        $this->playersStats[$playerName]['totalPlayTime'] += (int)$sessionMinutes;
        
        $this->saveStats();
        return true;
    }
    
    /**
     * Zapisz logout do bazy danych
     */
    private function recordLogoutDatabase($playerName) {
        $playerId = $this->getOrCreatePlayer($playerName);
        
        $stmt = $this->db->prepare("
            UPDATE session_logs 
            SET logout_time = NOW(), play_duration = TIMESTAMPDIFF(MINUTE, login_time, NOW())
            WHERE player_id = ? AND logout_time IS NULL
            ORDER BY session_id DESC LIMIT 1
        ");
        $stmt->bind_param("i", $playerId);
        $stmt->execute();
        
        $stmt = $this->db->prepare("
            UPDATE players 
            SET last_logout = NOW(), 
                total_play_time = total_play_time + COALESCE((
                    SELECT play_duration FROM session_logs 
                    WHERE player_id = ? AND logout_time IS NOT NULL
                    ORDER BY session_id DESC LIMIT 1
                ), 0)
            WHERE player_id = ?
        ");
        $stmt->bind_param("ii", $playerId, $playerId);
        $stmt->execute();
        
        return true;
    }
    
    /**
     * Aktualizuj streak bez śmierci
     */
    public function updateDeathStreak($playerName) {
        if (!isset($this->playersStats[$playerName])) {
            return false;
        }
        
        $this->playersStats[$playerName]['currentStreak']++;
        
        if ($this->playersStats[$playerName]['currentStreak'] > 
            $this->playersStats[$playerName]['bestStreak']) {
            $this->playersStats[$playerName]['bestStreak'] = 
                $this->playersStats[$playerName]['currentStreak'];
        }
        
        $this->saveStats();
        return $this->playersStats[$playerName]['currentStreak'];
    }
    
    /**
     * Pobierz statystyki gracza
     */
    public function getPlayerStats($playerName) {
        if ($this->useDatabase) {
            return $this->getPlayerStatsFromDatabase($playerName);
        }
        
        return $this->playersStats[$playerName] ?? null;
    }
    
    /**
     * Pobierz statystyki gracza z bazy danych
     */
    private function getPlayerStatsFromDatabase($playerName) {
        $stmt = $this->db->prepare("SELECT * FROM players WHERE player_name = ?");
        $stmt->bind_param("s", $playerName);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return null;
        }
        
        $row = $result->fetch_assoc();
        
        return [
            'playerName' => $row['player_name'],
            'deaths' => $row['deaths'],
            'kills' => $row['kills'],
            'totalPlayTime' => $row['total_play_time'],
            'bestStreak' => $row['best_streak'],
            'currentStreak' => $row['current_streak'],
            'logins' => $row['logins'],
            'firstJoin' => $row['first_join'],
            'lastLogin' => $row['last_login'],
            'lastLogout' => $row['last_logout'],
            'lastDeath' => $row['last_death']
        ];
    }
    
    /**
     * Wyświetl sformatowane statystyki gracza
     */
    public function displayPlayerStats($playerName) {
        $stats = $this->getPlayerStats($playerName);
        
        if (!$stats) {
            return "§cBrak danych dla gracza: $playerName";
        }
        
        $playTimeHours = floor($stats['totalPlayTime'] / 60);
        $playTimeMinutes = $stats['totalPlayTime'] % 60;
        
        $message = "§6=== STATYSTYKI: $playerName ===§r\n";
        $message .= "§c✠ Śmierci: {$stats['deaths']}\n";
        $message .= "§aZabójstwa: {$stats['kills']}\n";
        $message .= "§eWspółczynnik K/D: " . $this->getKDRatio($stats) . "\n";
        $message .= "§bCzas w grze: ${playTimeHours}h ${playTimeMinutes}min\n";
        $message .= "§dAktualna seria: {$stats['currentStreak']}\n";
        $message .= "§lNajlepszy streak: {$stats['bestStreak']}\n";
        $message .= "§7Ostatnia śmierć: {$stats['lastDeath']}\n";
        $message .= "§8Loginy: {$stats['logins']}\n";
        
        return $message;
    }
    
    /**
     * Oblicz współczynnik K/D
     */
    private function getKDRatio($stats) {
        if ($stats['deaths'] == 0) {
            return $stats['kills'] > 0 ? "∞" : "0.00";
        }
        return number_format($stats['kills'] / $stats['deaths'], 2);
    }
    
    /**
     * Pobierz top graczy
     */
    public function getTopPlayers($limit = 10, $sortBy = 'deaths') {
        if ($this->useDatabase) {
            return $this->getTopPlayersDatabase($limit, $sortBy);
        }
        
        $top = $this->playersStats;
        
        usort($top, function($a, $b) use ($sortBy) {
            return $b[$sortBy] <=> $a[$sortBy];
        });
        
        return array_slice($top, 0, $limit);
    }
    
    /**
     * Pobierz top graczy z bazy danych
     */
    private function getTopPlayersDatabase($limit = 10, $sortBy = 'deaths') {
        $columnMap = [
            'deaths' => 'deaths',
            'kills' => 'kills',
            'totalPlayTime' => 'total_play_time'
        ];
        
        $column = $columnMap[$sortBy] ?? 'deaths';
        
        $query = "SELECT * FROM players ORDER BY $column DESC LIMIT ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $topPlayers = [];
        while ($row = $result->fetch_assoc()) {
            $topPlayers[$row['player_name']] = [
                'playerName' => $row['player_name'],
                'deaths' => $row['deaths'],
                'kills' => $row['kills'],
                'totalPlayTime' => $row['total_play_time'],
                'bestStreak' => $row['best_streak'],
                'currentStreak' => $row['current_streak'],
                'logins' => $row['logins'],
                'firstJoin' => $row['first_join'],
                'lastLogin' => $row['last_login'],
                'lastLogout' => $row['last_logout'],
                'lastDeath' => $row['last_death']
            ];
        }
        
        return $topPlayers;
    }
    
    /**
     * Wyświetl ranking
     */
    public function displayTopDeaths($limit = 10) {
        $top = $this->getTopPlayers($limit, 'deaths');
        
        $message = "§6=== TOP $limit GRACZY (ŚMIERCI) ===§r\n";
        $position = 1;
        
        foreach ($top as $playerName => $stats) {
            $kd = $this->getKDRatio($stats);
            $message .= "§e$position. §f$playerName §c({$stats['deaths']} śmierci) ";
            $message .= "§7[K/D: $kd]\n";
            $position++;
        }
        
        return $message;
    }
    
    /**
     * Wyświetl ranking zabójców
     */
    public function displayTopKillers($limit = 10) {
        $top = $this->getTopPlayers($limit, 'kills');
        
        $message = "§6=== TOP $limit GRACZY (ZABÓJSTWA) ===§r\n";
        $position = 1;
        
        foreach ($top as $playerName => $stats) {
            $message .= "§a$position. §f$playerName §a({$stats['kills']} zabójstw)\n";
            $position++;
        }
        
        return $message;
    }
    
    /**
     * Wyświetl ranking czasu w grze
     */
    public function displayTopPlayTime($limit = 10) {
        $top = $this->getTopPlayers($limit, 'totalPlayTime');
        
        $message = "§6=== TOP $limit GRACZY (CZAS W GRZE) ===§r\n";
        $position = 1;
        
        foreach ($top as $playerName => $stats) {
            $hours = floor($stats['totalPlayTime'] / 60);
            $minutes = $stats['totalPlayTime'] % 60;
            $message .= "§b$position. §f$playerName §b(${hours}h ${minutes}min)\n";
            $position++;
        }
        
        return $message;
    }
    
    /**
     * Zresetuj statystyki gracza
     */
    public function resetPlayerStats($playerName) {
        if ($this->useDatabase) {
            return $this->resetPlayerStatsDatabase($playerName);
        }
        
        if (isset($this->playersStats[$playerName])) {
            $this->playersStats[$playerName] = $this->initializePlayerStats($playerName);
            $this->saveStats();
            return true;
        }
        return false;
    }
    
    /**
     * Zresetuj statystyki gracza w bazie danych
     */
    private function resetPlayerStatsDatabase($playerName) {
        $playerId = $this->getOrCreatePlayer($playerName);
        
        $stmt = $this->db->prepare("UPDATE players SET deaths = 0, kills = 0, total_play_time = 0, 
                                     best_streak = 0, current_streak = 0, logins = 0 WHERE player_id = ?");
        $stmt->bind_param("i", $playerId);
        return $stmt->execute();
    }
    
    /**
     * Inicjalizuj strukturę statystyk dla gracza
     */
    private function initializePlayerStats($playerName) {
        return [
            'playerName' => $playerName,
            'deaths' => 0,
            'kills' => 0,
            'totalPlayTime' => 0,
            'bestStreak' => 0,
            'currentStreak' => 0,
            'logins' => 0,
            'firstJoin' => date('Y-m-d H:i:s'),
            'lastLogin' => null,
            'lastLogout' => null,
            'lastDeath' => null,
            'currentSessionStart' => time()
        ];
    }
    
    /**
     * Pobierz wszystkie statystyki
     */
    public function getAllStats() {
        return $this->playersStats;
    }
}

?>

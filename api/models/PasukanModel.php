<?php
/**
 * Team Model
 * Handles all database operations for teams (pasukan) with managers, coaches, and athletes
 */

require_once __DIR__ . '/../../config/database.php';

class PasukanModel {
    private $db;
    private $stats = [];
    
    public function __construct() {
        $this->db = getDB();
            // If CONTINGENT user, limit stats to their kontinjen
            $sessionRole = $this->getSessionUserRole();
            $sessionKontinjen = $this->getSessionKontinjenId();
            if ($sessionRole === 'CONTINGENT' && $sessionKontinjen) {
                $stmt = $this->db->prepare("\n                    SELECT \n                        COUNT(*) AS total,\n                        SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS active,\n                        SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS inactive\n                    FROM table_pasukan\n                    WHERE deleted_at IS NULL AND kontinjen_id = :kontinjen_id\n                ");
                $stmt->execute([':kontinjen_id' => (int)$sessionKontinjen]);
            } else {
                $stmt = $this->db->query("\n                    SELECT \n                        COUNT(*) AS total,\n                        SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS active,\n                        SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS inactive\n                    FROM table_pasukan\n                    WHERE deleted_at IS NULL\n                ");
            }

            // store stats result for later use
            try {
                $this->stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) {
                $this->stats = [];
            }

    }

    /**
     * Helper: get session kontingen_id (if available)
     */
    private function getSessionKontinjenId() {
        if (class_exists('Session')) {
            if (session_status() === PHP_SESSION_NONE) {
                Session::start();
            }
            return Session::get('kontinjen_id') ?? null;
        }
        return null;
    }

    /**
     * Helper: get session user role (if available)
     */
    private function getSessionUserRole() {
        if (class_exists('Session')) {
            if (session_status() === PHP_SESSION_NONE) {
                Session::start();
            }
            return Session::get('user_role') ?? null;
        }
        return null;
    }
    
    /**
     * Create a new team with pengurus, jurulatih, and athletes
     * 
     * @param array $data Team data with pengurus, jurulatih, and athletes arrays
     * @return array Result with success status and data
     */
    public function create($data) {
        // Start transaction
        $this->db->beginTransaction();
        
        try {
            // Validate required fields
            if (empty($data['nama_pasukan'])) {
                throw new Exception('Team name is required');
            }
            
            // Enforce CONTINGENT session value: CONTINGENT users cannot set an arbitrary kontinjen_id
            $sessionRole = $this->getSessionUserRole();
            $sessionKontinjen = $this->getSessionKontinjenId();
            if ($sessionRole === 'CONTINGENT') {
                if (empty($sessionKontinjen)) {
                    throw new Exception('No contingent is assigned to this user.');
                }
                $data['kontinjen_id'] = (int)$sessionKontinjen;
            }

            if (empty($data['kontinjen_id'])) {
                throw new Exception('Contingent is required');
            }
            
            if (empty($data['sukan_id'])) {
                throw new Exception('Sport is required');
            }
            
            // 1. Insert team
            $stmt = $this->db->prepare("
                INSERT INTO table_pasukan (
                    kontinjen_id,
                    sukan_id,
                    nama_pasukan,
                    status,
                    created_by
                ) VALUES (
                    :kontinjen_id,
                    :sukan_id,
                    :nama_pasukan,
                    :status,
                    :created_by
                )
            ");
            
            $stmt->execute([
                ':kontinjen_id' => (int)$data['kontinjen_id'],
                ':sukan_id' => (int)$data['sukan_id'],
                ':nama_pasukan' => trim($data['nama_pasukan']),
                ':status' => isset($data['status']) ? (int)$data['status'] : 1,
                ':created_by' => $data['created_by'] ?? null
            ]);
            
            $pasukanId = $this->db->lastInsertId();
            
            // 2. Insert pengurus (manager) if provided
            if (!empty($data['pengurus']) && is_array($data['pengurus'])) {
                $pengurusStmt = $this->db->prepare("
                    INSERT INTO table_pasukan_pengurus (
                        pasukan_id,
                        nama,
                        no_kad_pengenalan,
                        no_telefon,
                        emel
                    ) VALUES (
                        :pasukan_id,
                        :nama,
                        :no_kad_pengenalan,
                        :no_telefon,
                        :emel
                    )
                ");
                
                foreach ($data['pengurus'] as $pengurus) {
                    if (!empty($pengurus['nama'])) {
                        $pengurusStmt->execute([
                            ':pasukan_id' => $pasukanId,
                            ':nama' => trim($pengurus['nama']),
                            ':no_kad_pengenalan' => !empty($pengurus['no_kad_pengenalan']) ? trim($pengurus['no_kad_pengenalan']) : null,
                            ':no_telefon' => !empty($pengurus['no_telefon']) ? trim($pengurus['no_telefon']) : null,
                            ':emel' => !empty($pengurus['emel']) ? trim($pengurus['emel']) : null
                        ]);
                    }
                }
            }
            
            // 3. Insert jurulatih (coach) if provided
            if (!empty($data['jurulatih']) && is_array($data['jurulatih'])) {
                $jurulatihStmt = $this->db->prepare("
                    INSERT INTO table_pasukan_jurulatih (
                        pasukan_id,
                        nama,
                        no_kad_pengenalan,
                        no_telefon,
                        emel
                    ) VALUES (
                        :pasukan_id,
                        :nama,
                        :no_kad_pengenalan,
                        :no_telefon,
                        :emel
                    )
                ");
                
                foreach ($data['jurulatih'] as $index => $jurulatih) {
                    if (!empty($jurulatih['nama'])) {
                        error_log("[PasukanModel::create] Inserting jurulatih #" . ($index + 1) . " - nama: '" . $jurulatih['nama'] . "', phone: '" . ($jurulatih['no_telefon'] ?? '') . "', email: '" . ($jurulatih['emel'] ?? '') . "'");
                        try {
                            $jurulatihStmt->execute([
                                ':pasukan_id' => $pasukanId,
                                ':nama' => trim($jurulatih['nama']),
                                ':no_kad_pengenalan' => !empty($jurulatih['no_kad_pengenalan']) && $jurulatih['no_kad_pengenalan'] !== '-' ? trim($jurulatih['no_kad_pengenalan']) : null,
                                ':no_telefon' => !empty($jurulatih['no_telefon']) && $jurulatih['no_telefon'] !== '-' ? trim($jurulatih['no_telefon']) : null,
                                ':emel' => !empty($jurulatih['emel']) && $jurulatih['emel'] !== '-' ? trim($jurulatih['emel']) : null
                            ]);
                        } catch (PDOException $e) {
                            error_log("[PasukanModel::create] Error inserting jurulatih: " . $e->getMessage());
                            throw new Exception("Unable to add a coach '" . $jurulatih['nama'] . "': " . $e->getMessage());
                        }
                    }
                }
            }
            
            // 4. Insert athletes if provided
            if (!empty($data['atlet']) && is_array($data['atlet'])) {
                $atletStmt = $this->db->prepare("
                    INSERT INTO table_pasukan_atlet (
                        pasukan_id,
                        nama,
                        no_kad_pengenalan,
                        no_matrik,
                        kategori_id
                    ) VALUES (
                        :pasukan_id,
                        :nama,
                        :no_kad_pengenalan,
                        :no_matrik,
                        :kategori_id
                    )
                ");
                
                foreach ($data['atlet'] as $index => $atlet) {
                    if (!empty($atlet['nama'])) {
                        $nama = trim($atlet['nama']);
                        $ic = !empty($atlet['no_kad_pengenalan']) ? trim($atlet['no_kad_pengenalan']) : null;
                        $matrik = !empty($atlet['no_matrik']) ? trim($atlet['no_matrik']) : null;
                        $kategoriId = !empty($atlet['kategori_id']) ? (int)$atlet['kategori_id'] : null;
                        
                        // CRITICAL: Ensure IC doesn't exceed 20 characters (database VARCHAR(20) limit)
                        if ($ic !== null && strlen($ic) > 20) {
                            error_log("[PasukanModel::create] WARNING: IC too long for athlete '$nama': '$ic' (length: " . strlen($ic) . "), truncating to 20 chars");
                            $ic = substr($ic, 0, 20);
                        }
                        
                        // Log the data being inserted for debugging
                        error_log("[PasukanModel::create] Inserting athlete #" . ($index + 1) . " - nama: '$nama', IC length: " . strlen($ic ?? '') . ", IC: '$ic', matrik: '$matrik', kategori_id: $kategoriId");
                        
                        try {
                            $atletStmt->execute([
                                ':pasukan_id' => $pasukanId,
                                ':nama' => $nama,
                                ':no_kad_pengenalan' => $ic,
                                ':no_matrik' => $matrik,
                                ':kategori_id' => $kategoriId
                            ]);
                        } catch (PDOException $e) {
                            error_log("[PasukanModel::create] Error inserting athlete: " . $e->getMessage());
                            error_log("[PasukanModel::create] PDO Error Code: " . $e->getCode());
                            error_log("[PasukanModel::create] Athlete data: " . json_encode([
                                'nama' => $nama,
                                'no_kad_pengenalan' => $ic,
                                'no_kad_pengenalan_length' => strlen($ic ?? ''),
                                'no_matrik' => $matrik,
                                'kategori_id' => $kategoriId
                            ]));
                            throw new Exception("Unable to add an athlete '$nama': " . $e->getMessage() . " (IC: '$ic', length: " . strlen($ic ?? '') . ")");
                        }
                    }
                }
            }
            
            // Commit transaction
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Team registered successfully',
                'data' => [
                    'id' => $pasukanId
                ]
            ];
        } catch (Exception $e) {
            // Rollback on error
            $this->db->rollBack();
            error_log('[PasukanModel::create] Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        } catch (PDOException $e) {
            // Rollback on error
            $this->db->rollBack();
            error_log('[PasukanModel::create] PDO Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'System error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Bulk create multiple teams
     * 
     * @param array $teamsData Array of team data arrays
     * @return array Result with success status, success count, failed count, and detailed errors
     */
    public function bulkCreate($teamsData) {
        $results = [
            'success' => true,
            'total' => count($teamsData),
            'success_count' => 0,
            'failed_count' => 0,
            'errors' => []
        ];
        
        foreach ($teamsData as $index => $data) {
            $teamIndex = $index + 1;
            try {
                // Validate required fields
                if (empty($data['nama_pasukan'])) {
                    throw new Exception('Team name is required');
                }
                
                if (empty($data['kontinjen_id'])) {
                    throw new Exception('Contingent is required');
                }
                
                if (empty($data['sukan_id'])) {
                    throw new Exception('Sport is required');
                }
                
                // Log team data being processed
                error_log('[PasukanModel::bulkCreate] Processing team ' . $teamIndex . ': ' . ($data['nama_pasukan'] ?? 'Unknown'));
                error_log('[PasukanModel::bulkCreate] Team data summary - pengurus: ' . count($data['pengurus'] ?? []) . ', jurulatih: ' . count($data['jurulatih'] ?? []) . ', atlet: ' . count($data['atlet'] ?? []));
                
                // Log athlete details for debugging
                if (!empty($data['atlet']) && is_array($data['atlet'])) {
                    foreach ($data['atlet'] as $atletIdx => $atlet) {
                        error_log('[PasukanModel::bulkCreate] Athlete ' . ($atletIdx + 1) . ': nama="' . ($atlet['nama'] ?? '') . '", IC="' . ($atlet['no_kad_pengenalan'] ?? '') . '" (len=' . strlen($atlet['no_kad_pengenalan'] ?? '') . '), matrik="' . ($atlet['no_matrik'] ?? '') . '", kategori_id=' . ($atlet['kategori_id'] ?? 'null'));
                    }
                }
                
                // Use existing create method for each team
                $result = $this->create($data);
                
                if ($result['success']) {
                    $results['success_count']++;
                } else {
                    $results['failed_count']++;
                    $errorMsg = $result['message'] ?? 'Unknown error';
                    $results['errors'][] = [
                        'team_index' => $teamIndex,
                        'team_name' => $data['nama_pasukan'] ?? 'Unknown',
                        'error' => $errorMsg
                    ];
                    error_log('[PasukanModel::bulkCreate] Failed team ' . $teamIndex . ': ' . $errorMsg);
                }
            } catch (Exception $e) {
                $results['failed_count']++;
                $errorMsg = $e->getMessage();
                
                // Collect problematic athlete data for display
                $problematicAthletes = [];
                if (!empty($data['atlet']) && is_array($data['atlet'])) {
                    foreach ($data['atlet'] as $atlet) {
                        $problematicAthletes[] = [
                            'nama' => $atlet['nama'] ?? '',
                            'ic' => $atlet['no_kad_pengenalan'] ?? '',
                            'ic_length' => strlen($atlet['no_kad_pengenalan'] ?? ''),
                            'matrik' => $atlet['no_matrik'] ?? '',
                            'kategori_id' => $atlet['kategori_id'] ?? null
                        ];
                    }
                }
                
                $results['errors'][] = [
                    'team_index' => $teamIndex,
                    'team_name' => $data['nama_pasukan'] ?? 'Unknown',
                    'error' => $errorMsg,
                    'team_data' => [
                        'kontinjen_id' => $data['kontinjen_id'] ?? null,
                        'sukan_id' => $data['sukan_id'] ?? null,
                        'atlet_count' => count($data['atlet'] ?? []),
                        'atlet_data' => $problematicAthletes
                    ]
                ];
                error_log('[PasukanModel::bulkCreate] Exception for team ' . $teamIndex . ': ' . $errorMsg);
                error_log('[PasukanModel::bulkCreate] Team data: ' . json_encode([
                    'nama_pasukan' => $data['nama_pasukan'] ?? null,
                    'kontinjen_id' => $data['kontinjen_id'] ?? null,
                    'sukan_id' => $data['sukan_id'] ?? null,
                    'atlet_count' => count($data['atlet'] ?? []),
                    'atlet_data' => $problematicAthletes
                ]));
            } catch (PDOException $e) {
                $results['failed_count']++;
                $errorMsg = 'System error: ' . $e->getMessage();
                
                // Collect problematic athlete data for display
                $problematicAthletes = [];
                if (!empty($data['atlet']) && is_array($data['atlet'])) {
                    foreach ($data['atlet'] as $atlet) {
                        $problematicAthletes[] = [
                            'nama' => $atlet['nama'] ?? '',
                            'ic' => $atlet['no_kad_pengenalan'] ?? '',
                            'ic_length' => strlen($atlet['no_kad_pengenalan'] ?? ''),
                            'matrik' => $atlet['no_matrik'] ?? '',
                            'kategori_id' => $atlet['kategori_id'] ?? null
                        ];
                    }
                }
                
                $results['errors'][] = [
                    'team_index' => $teamIndex,
                    'team_name' => $data['nama_pasukan'] ?? 'Unknown',
                    'error' => $errorMsg,
                    'team_data' => [
                        'kontinjen_id' => $data['kontinjen_id'] ?? null,
                        'sukan_id' => $data['sukan_id'] ?? null,
                        'atlet_count' => count($data['atlet'] ?? []),
                        'atlet_data' => $problematicAthletes
                    ]
                ];
                error_log('[PasukanModel::bulkCreate] PDO Error for team ' . $teamIndex . ': ' . $e->getMessage());
                error_log('[PasukanModel::bulkCreate] PDO Error code: ' . $e->getCode());
            }
        }
        
        // Set overall success based on whether any teams were successfully created
        $results['success'] = $results['success_count'] > 0;
        
        return $results;
    }
    
    /**
     * Get all teams with pagination and filters
     * 
     * @param array $params Query parameters (limit, offset, search, kontinjen_id, sukan_id, status)
     * @return array Result with success status and data
     */
    public function getAll($params = []) {
        try {
            // Check if table exists
            $checkTable = $this->db->query("SHOW TABLES LIKE 'table_pasukan'");
            if ($checkTable->rowCount() === 0) {
                return [
                    'success' => false,
                    'message' => 'The database table does not exist. Ensure table_pasukan has been created.',
                    'data' => []
                ];
            }
            
            $limit = $params['limit'] ?? 50;
            $offset = $params['offset'] ?? 0;
            $search = $params['search'] ?? '';
            $kontinjenId = $params['kontinjen_id'] ?? null;
            // Enforce session-based kontinjen scope for CONTINGENT users
            $sessionRole = $this->getSessionUserRole();
            $sessionKontinjen = $this->getSessionKontinjenId();
            if ($sessionRole === 'CONTINGENT') {
                // override any provided kontingen filter with the session value
                $kontinjenId = $sessionKontinjen;
            }
            $sukanId = $params['sukan_id'] ?? null;
            $status = $params['status'] ?? null;
            
            $where = ['p.deleted_at IS NULL'];
            $bindings = [];
            
            if (!empty($search)) {
                // Use unique named placeholders for each occurrence to avoid PDO "Invalid parameter number"
                $where[] = "(p.nama_pasukan LIKE :search_p 
                            OR u.nama_universiti LIKE :search_u
                            OR s.nama_sukan LIKE :search_s
                            OR peng.nama LIKE :search_peng
                            OR jur.nama LIKE :search_jur
                            OR atl.nama LIKE :search_atl)";
                $like = '%' . $search . '%';
                $bindings[':search_p'] = $like;
                $bindings[':search_u'] = $like;
                $bindings[':search_s'] = $like;
                $bindings[':search_peng'] = $like;
                $bindings[':search_jur'] = $like;
                $bindings[':search_atl'] = $like;
            }
            
            if ($kontinjenId !== null) {
                $where[] = "p.kontinjen_id = :kontinjen_id";
                $bindings[':kontinjen_id'] = (int)$kontinjenId;
            }
            
            if ($sukanId !== null) {
                $where[] = "p.sukan_id = :sukan_id";
                $bindings[':sukan_id'] = (int)$sukanId;
            }
            
            if ($status !== null) {
                $where[] = "p.status = :status";
                $bindings[':status'] = (int)$status;
            }
            
            $whereClause = implode(' AND ', $where);
            
            $sql = "
                SELECT 
                    p.id,
                    p.kontinjen_id,
                    p.sukan_id,
                    p.nama_pasukan,
                    p.status,
                    p.created_at,
                    p.updated_at,
                    u.nama_universiti,
                    u.nama_pendek AS kontinjen_nama_pendek,
                    s.nama_sukan,
                    COUNT(DISTINCT peng.id) AS pengurus_count,
                    COUNT(DISTINCT jur.id) AS jurulatih_count,
                    COUNT(DISTINCT atl.id) AS atlet_count,
                    GROUP_CONCAT(DISTINCT peng.nama SEPARATOR ', ') AS pengurus_list,
                    GROUP_CONCAT(DISTINCT jur.nama SEPARATOR ', ') AS jurulatih_list,
                    creator.full_name AS created_by_name,
                    updater.full_name AS updated_by_name
                FROM table_pasukan p
                LEFT JOIN table_kontinjen k ON p.kontinjen_id = k.id
                LEFT JOIN table_ref_universiti u ON k.kod_universiti = u.kod_universiti AND u.deleted_at IS NULL
                LEFT JOIN table_sukan s ON p.sukan_id = s.id
                LEFT JOIN table_pasukan_pengurus peng ON p.id = peng.pasukan_id AND peng.deleted_at IS NULL
                LEFT JOIN table_pasukan_jurulatih jur ON p.id = jur.pasukan_id AND jur.deleted_at IS NULL
                LEFT JOIN table_pasukan_atlet atl ON p.id = atl.pasukan_id AND atl.deleted_at IS NULL
                LEFT JOIN users creator ON p.created_by = creator.id
                LEFT JOIN users updater ON p.updated_by = updater.id
                WHERE {$whereClause}
                GROUP BY p.id
                ORDER BY p.created_at DESC
                LIMIT :limit OFFSET :offset
            ";
            
            $stmt = $this->db->prepare($sql);
            
            foreach ($bindings as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count
            $countSql = "
                SELECT COUNT(DISTINCT p.id) AS total
                FROM table_pasukan p
                LEFT JOIN table_kontinjen k ON p.kontinjen_id = k.id
                LEFT JOIN table_ref_universiti u ON k.kod_universiti = u.kod_universiti AND u.deleted_at IS NULL
                LEFT JOIN table_sukan s ON p.sukan_id = s.id
                LEFT JOIN table_pasukan_pengurus peng ON p.id = peng.pasukan_id AND peng.deleted_at IS NULL
                LEFT JOIN table_pasukan_jurulatih jur ON p.id = jur.pasukan_id AND jur.deleted_at IS NULL
                LEFT JOIN table_pasukan_atlet atl ON p.id = atl.pasukan_id AND atl.deleted_at IS NULL
                WHERE {$whereClause}
            ";
            
            $countStmt = $this->db->prepare($countSql);
            foreach ($bindings as $key => $value) {
                $countStmt->bindValue($key, $value);
            }
            $countStmt->execute();
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            return [
                'success' => true,
                'data' => $teams,
                'total' => (int)$total,
                'limit' => (int)$limit,
                'offset' => (int)$offset
            ];
        } catch (PDOException $e) {
            error_log('[PasukanModel::getAll] Error: ' . $e->getMessage());
            error_log('[PasukanModel::getAll] SQL State: ' . $e->getCode());
            
            // Check if it's a table doesn't exist error
            if (strpos($e->getMessage(), "doesn't exist") !== false || 
                strpos($e->getMessage(), "Unknown table") !== false) {
                return [
                    'success' => false,
                    'message' => 'The database table does not exist. Run the SQL migration to create table_pasukan.',
                    'data' => []
                ];
            }
            
            return [
                'success' => false,
                'message' => 'System error: ' . (defined('DEBUG_MODE') && DEBUG_MODE ? $e->getMessage() : 'Unable to load team data'),
                'data' => []
            ];
        }
    }
    
    /**
     * Get single team by ID with all related data
     * 
     * @param int $id Team ID
     * @return array Result with success status and data
     */
    public function getById($id) {
        try {
            // Check if table exists
            $checkTable = $this->db->query("SHOW TABLES LIKE 'table_pasukan'");
            if ($checkTable->rowCount() === 0) {
                return [
                    'success' => false,
                    'message' => 'The database table does not exist. Ensure table_pasukan has been created.',
                    'data' => null
                ];
            }
            
            $stmt = $this->db->prepare("
                SELECT 
                    p.id,
                    p.kontinjen_id,
                    p.sukan_id,
                    p.nama_pasukan,
                    p.status,
                    p.created_at,
                    p.updated_at,
                    p.created_by,
                    p.updated_by,
                    u.nama_universiti,
                    u.nama_pendek AS kontinjen_nama_pendek,
                    s.nama_sukan,
                    creator.full_name AS created_by_name,
                    updater.full_name AS updated_by_name
                FROM table_pasukan p
                LEFT JOIN table_kontinjen k ON p.kontinjen_id = k.id
                LEFT JOIN table_ref_universiti u ON k.kod_universiti = u.kod_universiti AND u.deleted_at IS NULL
                LEFT JOIN table_sukan s ON p.sukan_id = s.id
                LEFT JOIN users creator ON p.created_by = creator.id
                LEFT JOIN users updater ON p.updated_by = updater.id
                WHERE p.id = :id 
                AND p.deleted_at IS NULL
            ");
            
            $stmt->execute([':id' => $id]);
            $team = $stmt->fetch(PDO::FETCH_ASSOC);

            // Enforce CONTINGENT scope: if logged-in user is CONTINGENT, ensure the team belongs to their kontinjen
            $sessionRole = $this->getSessionUserRole();
            $sessionKontinjen = $this->getSessionKontinjenId();
            if ($sessionRole === 'CONTINGENT') {
                if (empty($team) || (int)$team['kontinjen_id'] !== (int)$sessionKontinjen) {
                    return [
                        'success' => false,
                        'message' => 'Teams not found',
                        'data' => null
                    ];
                }
            }
            
            if ($team) {
                // Get pengurus
                $pengurusStmt = $this->db->prepare("
                    SELECT 
                        id,
                        nama,
                        no_kad_pengenalan,
                        no_telefon,
                        emel
                    FROM table_pasukan_pengurus
                    WHERE pasukan_id = :pasukan_id
                    AND deleted_at IS NULL
                    ORDER BY created_at ASC
                ");
                $pengurusStmt->execute([':pasukan_id' => $id]);
                $team['pengurus'] = $pengurusStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Get jurulatih
                $jurulatihStmt = $this->db->prepare("
                    SELECT 
                        id,
                        nama,
                        no_kad_pengenalan,
                        no_telefon,
                        emel
                    FROM table_pasukan_jurulatih
                    WHERE pasukan_id = :pasukan_id
                    AND deleted_at IS NULL
                    ORDER BY created_at ASC
                ");
                $jurulatihStmt->execute([':pasukan_id' => $id]);
                $team['jurulatih'] = $jurulatihStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Get athletes
                $atletStmt = $this->db->prepare("
                    SELECT 
                        id,
                        nama,
                        no_kad_pengenalan,
                        no_matrik,
                        kategori_id
                    FROM table_pasukan_atlet
                    WHERE pasukan_id = :pasukan_id
                    AND deleted_at IS NULL
                    ORDER BY created_at ASC
                ");
                $atletStmt->execute([':pasukan_id' => $id]);
                $team['atlet'] = $atletStmt->fetchAll(PDO::FETCH_ASSOC);
                
                return [
                    'success' => true,
                    'data' => $team
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Teams not found',
                'data' => null
            ];
        } catch (PDOException $e) {
            error_log('[PasukanModel::getById] Error: ' . $e->getMessage());
            
            // Check if it's a table doesn't exist error
            if (strpos($e->getMessage(), "doesn't exist") !== false || 
                strpos($e->getMessage(), "Unknown table") !== false) {
                return [
                    'success' => false,
                    'message' => 'The database table does not exist. Run the SQL migration to create table_pasukan.',
                    'data' => null
                ];
            }
            
            return [
                'success' => false,
                'message' => 'System error: ' . (defined('DEBUG_MODE') && DEBUG_MODE ? $e->getMessage() : 'Unable to load team data'),
                'data' => null
            ];
        }
    }
    
    /**
     * Update team
     * 
     * @param int $id Team ID
     * @param array $data Updated data
     * @return array Result with success status and message
     */
    public function update($id, $data) {
        // Start transaction
        $this->db->beginTransaction();
        
        try {
            // Check if team exists
            $checkStmt = $this->db->prepare("SELECT id FROM table_pasukan WHERE id = :id AND deleted_at IS NULL");
            $checkStmt->execute([':id' => $id]);
            if (!$checkStmt->fetch()) {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'message' => 'Teams not found'
                ];
            }
            
            // Enforce CONTINGENT scope: ensure user can only update teams in their contingent
            $sessionRole = $this->getSessionUserRole();
            $sessionKontinjen = $this->getSessionKontinjenId();
            if ($sessionRole === 'CONTINGENT') {
                // Check team belongs to user's contingent
                $check = $this->db->prepare("SELECT kontinjen_id FROM table_pasukan WHERE id = :id AND deleted_at IS NULL");
                $check->execute([':id' => $id]);
                $row = $check->fetch(PDO::FETCH_ASSOC);
                if (!$row || (int)$row['kontinjen_id'] !== (int)$sessionKontinjen) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'You do not have permission to update this team'];
                }
                // Force kontinjen_id to session value
                $data['kontinjen_id'] = (int)$sessionKontinjen;
            }

            // 1. Update team
            $stmt = $this->db->prepare("
                UPDATE table_pasukan
                SET 
                    kontinjen_id = :kontinjen_id,
                    sukan_id = :sukan_id,
                    nama_pasukan = :nama_pasukan,
                    status = :status,
                    updated_by = :updated_by,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
                AND deleted_at IS NULL
            ");
            
            $result = $stmt->execute([
                ':id' => $id,
                ':kontinjen_id' => (int)$data['kontinjen_id'],
                ':sukan_id' => (int)$data['sukan_id'],
                ':nama_pasukan' => trim($data['nama_pasukan']),
                ':status' => isset($data['status']) ? (int)$data['status'] : 1,
                ':updated_by' => $data['updated_by'] ?? null
            ]);
            
            if (!$result) {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'message' => 'Unable to update the team'
                ];
            }
            
            // 2. Handle pengurus
            if (isset($data['pengurus']) && is_array($data['pengurus'])) {
                // Soft delete existing pengurus
                $deletePengurusStmt = $this->db->prepare("
                    UPDATE table_pasukan_pengurus
                    SET deleted_at = CURRENT_TIMESTAMP
                    WHERE pasukan_id = :pasukan_id
                    AND deleted_at IS NULL
                ");
                $deletePengurusStmt->execute([':pasukan_id' => $id]);
                
                // Insert new pengurus
                if (!empty($data['pengurus'])) {
                    $pengurusStmt = $this->db->prepare("
                        INSERT INTO table_pasukan_pengurus (
                            pasukan_id,
                            nama,
                            no_kad_pengenalan,
                            no_telefon,
                            emel
                        ) VALUES (
                            :pasukan_id,
                            :nama,
                            :no_kad_pengenalan,
                            :no_telefon,
                            :emel
                        )
                    ");
                    
                    foreach ($data['pengurus'] as $pengurus) {
                        if (!empty($pengurus['nama'])) {
                            $pengurusStmt->execute([
                                ':pasukan_id' => $id,
                                ':nama' => trim($pengurus['nama']),
                                ':no_kad_pengenalan' => !empty($pengurus['no_kad_pengenalan']) ? trim($pengurus['no_kad_pengenalan']) : null,
                                ':no_telefon' => !empty($pengurus['no_telefon']) ? trim($pengurus['no_telefon']) : null,
                                ':emel' => !empty($pengurus['emel']) ? trim($pengurus['emel']) : null
                            ]);
                        }
                    }
                }
            }
            
            // 3. Handle jurulatih
            if (isset($data['jurulatih']) && is_array($data['jurulatih'])) {
                // Soft delete existing jurulatih
                $deleteJurulatihStmt = $this->db->prepare("
                    UPDATE table_pasukan_jurulatih
                    SET deleted_at = CURRENT_TIMESTAMP
                    WHERE pasukan_id = :pasukan_id
                    AND deleted_at IS NULL
                ");
                $deleteJurulatihStmt->execute([':pasukan_id' => $id]);
                
                // Insert new jurulatih
                if (!empty($data['jurulatih'])) {
                    $jurulatihStmt = $this->db->prepare("
                        INSERT INTO table_pasukan_jurulatih (
                            pasukan_id,
                            nama,
                            no_kad_pengenalan,
                            no_telefon,
                            emel
                        ) VALUES (
                            :pasukan_id,
                            :nama,
                            :no_kad_pengenalan,
                            :no_telefon,
                            :emel
                        )
                    ");
                    
                    foreach ($data['jurulatih'] as $jurulatih) {
                        if (!empty($jurulatih['nama'])) {
                            $jurulatihStmt->execute([
                                ':pasukan_id' => $id,
                                ':nama' => trim($jurulatih['nama']),
                                ':no_kad_pengenalan' => !empty($jurulatih['no_kad_pengenalan']) ? trim($jurulatih['no_kad_pengenalan']) : null,
                                ':no_telefon' => !empty($jurulatih['no_telefon']) ? trim($jurulatih['no_telefon']) : null,
                                ':emel' => !empty($jurulatih['emel']) ? trim($jurulatih['emel']) : null
                            ]);
                        }
                    }
                }
            }
            
            // 4. Handle athletes
            if (isset($data['atlet']) && is_array($data['atlet'])) {
                // Soft delete existing athletes
                $deleteAtletStmt = $this->db->prepare("
                    UPDATE table_pasukan_atlet
                    SET deleted_at = CURRENT_TIMESTAMP
                    WHERE pasukan_id = :pasukan_id
                    AND deleted_at IS NULL
                ");
                $deleteAtletStmt->execute([':pasukan_id' => $id]);
                
                // Insert new athletes
                if (!empty($data['atlet'])) {
                    $atletStmt = $this->db->prepare("
                        INSERT INTO table_pasukan_atlet (
                            pasukan_id,
                            nama,
                            no_kad_pengenalan,
                            no_matrik,
                            kategori_id
                        ) VALUES (
                            :pasukan_id,
                            :nama,
                            :no_kad_pengenalan,
                            :no_matrik,
                            :kategori_id
                        )
                    ");
                    
                    foreach ($data['atlet'] as $atlet) {
                        if (!empty($atlet['nama'])) {
                            $atletStmt->execute([
                                ':pasukan_id' => $id,
                                ':nama' => trim($atlet['nama']),
                                ':no_kad_pengenalan' => !empty($atlet['no_kad_pengenalan']) ? trim($atlet['no_kad_pengenalan']) : null,
                                ':no_matrik' => !empty($atlet['no_matrik']) ? trim($atlet['no_matrik']) : null,
                                ':kategori_id' => !empty($atlet['kategori_id']) ? (int)$atlet['kategori_id'] : null
                            ]);
                        }
                    }
                }
            }
            
            // Commit transaction
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Team updated successfully'
            ];
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log('[PasukanModel::update] Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'System error: ' . $e->getMessage()
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log('[PasukanModel::update] Exception: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Soft delete team
     * 
     * @param int $id Team ID
     * @param int $deleted_by User ID who deleted
     * @return array Result with success status and message
     */
    public function delete($id, $deleted_by = null) {
        // Start transaction
        $this->db->beginTransaction();
        
        try {
            // Enforce CONTINGENT scope: ensure user can only delete teams in their contingent
            $sessionRole = $this->getSessionUserRole();
            $sessionKontinjen = $this->getSessionKontinjenId();
            if ($sessionRole === 'CONTINGENT') {
                $check = $this->db->prepare("SELECT kontinjen_id FROM table_pasukan WHERE id = :id AND deleted_at IS NULL");
                $check->execute([':id' => $id]);
                $row = $check->fetch(PDO::FETCH_ASSOC);
                if (!$row || (int)$row['kontinjen_id'] !== (int)$sessionKontinjen) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'You do not have permission to delete this team'];
                }
            }

            // Soft delete team
            $stmt = $this->db->prepare("
                UPDATE table_pasukan
                SET 
                    deleted_at = CURRENT_TIMESTAMP,
                    updated_by = :updated_by,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
                AND deleted_at IS NULL
            ");
            
            $result = $stmt->execute([
                ':id' => $id,
                ':updated_by' => $deleted_by
            ]);
            
            if ($result && $stmt->rowCount() > 0) {
                // Soft delete related records (cascade via application logic)
                // Note: Foreign keys with CASCADE will handle this, but we do it explicitly for consistency
                $pengurusStmt = $this->db->prepare("
                    UPDATE table_pasukan_pengurus
                    SET deleted_at = CURRENT_TIMESTAMP
                    WHERE pasukan_id = :pasukan_id
                    AND deleted_at IS NULL
                ");
                $pengurusStmt->execute([':pasukan_id' => $id]);
                
                $jurulatihStmt = $this->db->prepare("
                    UPDATE table_pasukan_jurulatih
                    SET deleted_at = CURRENT_TIMESTAMP
                    WHERE pasukan_id = :pasukan_id
                    AND deleted_at IS NULL
                ");
                $jurulatihStmt->execute([':pasukan_id' => $id]);
                
                $atletStmt = $this->db->prepare("
                    UPDATE table_pasukan_atlet
                    SET deleted_at = CURRENT_TIMESTAMP
                    WHERE pasukan_id = :pasukan_id
                    AND deleted_at IS NULL
                ");
                $atletStmt->execute([':pasukan_id' => $id]);
                
                $this->db->commit();
                
                return [
                    'success' => true,
                    'message' => 'Team deleted successfully'
                ];
            }
            
            $this->db->rollBack();
            return [
                'success' => false,
                'message' => 'Teams not found'
            ];
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log('[PasukanModel::delete] Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'System error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get team statistics
     * 
     * @return array Result with success status and statistics
     */
    public function getStatistics() {
        try {
            // Check if table exists
            $checkTable = $this->db->query("SHOW TABLES LIKE 'table_pasukan'");
            if ($checkTable->rowCount() === 0) {
                return [
                    'success' => true,
                    'data' => [
                        'total' => 0,
                        'active' => 0,
                        'inactive' => 0
                    ]
                ];
            }
            
            $stmt = $this->db->query("
                SELECT 
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS inactive
                FROM table_pasukan
                WHERE deleted_at IS NULL
            ");
            
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'data' => [
                    'total' => (int)$stats['total'],
                    'active' => (int)$stats['active'],
                    'inactive' => (int)$stats['inactive']
                ]
            ];
        } catch (PDOException $e) {
            error_log('[PasukanModel::getStatistics] Error: ' . $e->getMessage());
            return [
                'success' => true, // Return success with zeros to prevent breaking the UI
                'data' => [
                    'total' => 0,
                    'active' => 0,
                    'inactive' => 0
                ]
            ];
        }
    }
    
    /**
     * Count total teams
     * 
     * @return int Total count
     */
    public function count() {
        try {
            $stmt = $this->db->query("
                SELECT COUNT(*) AS total
                FROM table_pasukan
                WHERE deleted_at IS NULL
            ");
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)$result['total'];
        } catch (PDOException $e) {
            error_log('[PasukanModel::count] Error: ' . $e->getMessage());
            return 0;
        }
    }
}


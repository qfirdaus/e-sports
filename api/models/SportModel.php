<?php
/**
 * Sport Model
 * Handles all database operations for sports (sukan) and categories (kategori)
 */

require_once __DIR__ . '/../../config/database.php';

class SportModel {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    /**
     * Create a new sport with categories
     * 
     * @param array $data Sport data with categories array
     * @return array Result with success status and data
     */
    public function create($data) {
        // Start transaction
        $this->db->beginTransaction();
        
        try {
            // Validate required fields
            if (empty($data['nama_sukan'])) {
                throw new Exception('Sport name is required');
            }
            
            // Check if sport name already exists (case-insensitive)
            $checkStmt = $this->db->prepare("
                SELECT id FROM table_sukan 
                WHERE LOWER(nama_sukan) = LOWER(:nama_sukan) 
                AND deleted_at IS NULL
            ");
            $checkStmt->execute([':nama_sukan' => trim($data['nama_sukan'])]);
            if ($checkStmt->fetch()) {
                throw new Exception('The sport name already exists');
            }
            
            // Check if kod_sukan already exists (if provided)
            if (!empty($data['kod_sukan'])) {
                $checkCodeStmt = $this->db->prepare("
                    SELECT id FROM table_sukan 
                    WHERE kod_sukan = :kod_sukan 
                    AND deleted_at IS NULL
                ");
                $checkCodeStmt->execute([':kod_sukan' => trim($data['kod_sukan'])]);
                if ($checkCodeStmt->fetch()) {
                    throw new Exception('Sport code already exists');
                }
            }
            
            // 1. Insert sport
            $stmt = $this->db->prepare("
                INSERT INTO table_sukan (
                    nama_sukan,
                    kod_sukan,
                    keterangan,
                    status,
                    created_by
                ) VALUES (
                    :nama_sukan,
                    :kod_sukan,
                    :keterangan,
                    :status,
                    :created_by
                )
            ");
            
            $stmt->execute([
                ':nama_sukan' => trim($data['nama_sukan']),
                ':kod_sukan' => !empty($data['kod_sukan']) ? trim($data['kod_sukan']) : null,
                ':keterangan' => !empty($data['keterangan']) ? trim($data['keterangan']) : null,
                ':status' => isset($data['status']) ? (int)$data['status'] : 1,
                ':created_by' => $data['created_by'] ?? null
            ]);
            
            $sportId = $this->db->lastInsertId();
            $categoryIds = [];
            
            // 2. Insert categories if provided
            if (!empty($data['categories']) && is_array($data['categories'])) {
                $categoryStmt = $this->db->prepare("
                    INSERT INTO table_kategori (
                        sukan_id,
                        nama_kategori,
                        kod_kategori,
                        keterangan,
                        penilaian,
                        status,
                        created_by
                    ) VALUES (
                        :sukan_id,
                        :nama_kategori,
                        :kod_kategori,
                        :keterangan,
                        :penilaian,
                        :status,
                        :created_by
                    )
                ");
                
                foreach ($data['categories'] as $category) {
                    if (!empty($category['nama_kategori'])) {
                        // Check for duplicate category name per sport
                        $checkCatStmt = $this->db->prepare("
                            SELECT id FROM table_kategori 
                            WHERE sukan_id = :sukan_id 
                            AND LOWER(nama_kategori) = LOWER(:nama_kategori)
                            AND deleted_at IS NULL
                        ");
                        $checkCatStmt->execute([
                            ':sukan_id' => $sportId,
                            ':nama_kategori' => trim($category['nama_kategori'])
                        ]);
                        if ($checkCatStmt->fetch()) {
                            continue; // Skip duplicate category
                        }
                        
                        $categoryStmt->execute([
                            ':sukan_id' => $sportId,
                            ':nama_kategori' => trim($category['nama_kategori']),
                            ':kod_kategori' => !empty($category['kod_kategori']) ? trim($category['kod_kategori']) : null,
                            ':keterangan' => !empty($category['keterangan']) ? trim($category['keterangan']) : null,
                            ':penilaian' => !empty($category['penilaian']) && in_array($category['penilaian'], ['berkumpulan', 'individu']) ? $category['penilaian'] : null,
                            ':status' => isset($category['status']) ? (int)$category['status'] : 1,
                            ':created_by' => $data['created_by'] ?? null
                        ]);
                        $categoryIds[] = $this->db->lastInsertId();
                    }
                }
            }
            
            // Commit transaction
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Sport and categories registered successfully',
                'data' => [
                    'sport_id' => $sportId,
                    'category_ids' => $categoryIds,
                    'categories_count' => count($categoryIds)
                ]
            ];
        } catch (Exception $e) {
            // Rollback on error
            $this->db->rollBack();
            error_log('[SportModel::create] Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        } catch (PDOException $e) {
            // Rollback on error
            $this->db->rollBack();
            error_log('[SportModel::create] PDO Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'System error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get all sports with pagination and filters
     * 
     * @param array $params Query parameters (limit, offset, search, status)
     * @return array Result with success status and data
     */
    public function getAll($params = []) {
        try {
            $limit = $params['limit'] ?? 50;
            $offset = $params['offset'] ?? 0;
            $search = $params['search'] ?? '';
            $status = $params['status'] ?? null;
            
            $where = ['s.deleted_at IS NULL'];
            $bindings = [];
            
            if (!empty($search)) {
                $where[] = "(s.nama_sukan LIKE :search 
                            OR s.kod_sukan LIKE :search 
                            OR k.nama_kategori LIKE :search)";
                $bindings[':search'] = '%' . $search . '%';
            }
            
            if ($status !== null) {
                $where[] = "s.status = :status";
                $bindings[':status'] = $status;
            }
            
            $whereClause = implode(' AND ', $where);
            
            $sql = "
                SELECT 
                    s.id,
                    s.nama_sukan,
                    s.kod_sukan,
                    s.keterangan,
                    s.status,
                    s.created_at,
                    s.updated_at,
                    COUNT(DISTINCT k.id) AS categories_count,
                    GROUP_CONCAT(DISTINCT k.nama_kategori ORDER BY k.nama_kategori SEPARATOR ', ') AS categories_list,
                    creator.full_name AS created_by_name,
                    updater.full_name AS updated_by_name
                FROM table_sukan s
                LEFT JOIN table_kategori k ON s.id = k.sukan_id AND k.deleted_at IS NULL
                LEFT JOIN users creator ON s.created_by = creator.id
                LEFT JOIN users updater ON s.updated_by = updater.id
                WHERE {$whereClause}
                GROUP BY s.id
                ORDER BY s.created_at DESC
                LIMIT :limit OFFSET :offset
            ";
            
            $stmt = $this->db->prepare($sql);
            
            foreach ($bindings as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $sports = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count
            $countSql = "
                SELECT COUNT(DISTINCT s.id) AS total
                FROM table_sukan s
                LEFT JOIN table_kategori k ON s.id = k.sukan_id AND k.deleted_at IS NULL
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
                'data' => $sports,
                'total' => (int)$total,
                'limit' => (int)$limit,
                'offset' => (int)$offset
            ];
        } catch (PDOException $e) {
            error_log('[SportModel::getAll] Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'System error: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }
    
    /**
     * Get single sport by ID with all categories
     * 
     * @param int $id Sport ID
     * @return array Result with success status and data
     */
    public function getById($id) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    s.id,
                    s.nama_sukan,
                    s.kod_sukan,
                    s.keterangan,
                    s.status,
                    s.created_at,
                    s.updated_at,
                    s.created_by,
                    s.updated_by,
                    creator.full_name AS created_by_name,
                    updater.full_name AS updated_by_name
                FROM table_sukan s
                LEFT JOIN users creator ON s.created_by = creator.id
                LEFT JOIN users updater ON s.updated_by = updater.id
                WHERE s.id = :id 
                AND s.deleted_at IS NULL
            ");
            
            $stmt->execute([':id' => $id]);
            $sport = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($sport) {
                // Get categories for this sport
                $catStmt = $this->db->prepare("
                    SELECT 
                        k.id,
                        k.nama_kategori,
                        k.kod_kategori,
                        k.keterangan,
                        k.penilaian,
                        k.status,
                        k.created_at
                    FROM table_kategori k
                    WHERE k.sukan_id = :sukan_id
                    AND k.deleted_at IS NULL
                    ORDER BY k.created_at ASC
                ");
                $catStmt->execute([':sukan_id' => $id]);
                $sport['categories'] = $catStmt->fetchAll(PDO::FETCH_ASSOC);
                
                return [
                    'success' => true,
                    'data' => $sport
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Sports not found',
                'data' => null
            ];
        } catch (PDOException $e) {
            error_log('[SportModel::getById] Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'System error: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    /**
     * Update sport
     * 
     * @param int $id Sport ID
     * @param array $data Updated data
     * @return array Result with success status and message
     */
    public function update($id, $data) {
        // Start transaction
        $this->db->beginTransaction();
        
        try {
            // Check if sport exists
            $checkStmt = $this->db->prepare("SELECT id FROM table_sukan WHERE id = :id AND deleted_at IS NULL");
            $checkStmt->execute([':id' => $id]);
            if (!$checkStmt->fetch()) {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'message' => 'Sports not found'
                ];
            }
            
            // Check if new name conflicts with existing sport
            if (!empty($data['nama_sukan'])) {
                $nameCheckStmt = $this->db->prepare("
                    SELECT id FROM table_sukan 
                    WHERE LOWER(nama_sukan) = LOWER(:nama_sukan) 
                    AND id != :id
                    AND deleted_at IS NULL
                ");
                $nameCheckStmt->execute([
                    ':nama_sukan' => trim($data['nama_sukan']),
                    ':id' => $id
                ]);
                if ($nameCheckStmt->fetch()) {
                    $this->db->rollBack();
                    return [
                        'success' => false,
                        'message' => 'The sport name already exists'
                    ];
                }
            }
            
            // Check if new kod_sukan conflicts (if provided)
            if (!empty($data['kod_sukan'])) {
                $codeCheckStmt = $this->db->prepare("
                    SELECT id FROM table_sukan 
                    WHERE kod_sukan = :kod_sukan 
                    AND id != :id
                    AND deleted_at IS NULL
                ");
                $codeCheckStmt->execute([
                    ':kod_sukan' => trim($data['kod_sukan']),
                    ':id' => $id
                ]);
                if ($codeCheckStmt->fetch()) {
                    $this->db->rollBack();
                    return [
                        'success' => false,
                        'message' => 'Sport code already exists'
                    ];
                }
            }
            
            // 1. Update sport
            $stmt = $this->db->prepare("
                UPDATE table_sukan
                SET 
                    nama_sukan = :nama_sukan,
                    kod_sukan = :kod_sukan,
                    keterangan = :keterangan,
                    status = :status,
                    updated_by = :updated_by,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
                AND deleted_at IS NULL
            ");
            
            $result = $stmt->execute([
                ':id' => $id,
                ':nama_sukan' => trim($data['nama_sukan']),
                ':kod_sukan' => !empty($data['kod_sukan']) ? trim($data['kod_sukan']) : null,
                ':keterangan' => !empty($data['keterangan']) ? trim($data['keterangan']) : null,
                ':status' => isset($data['status']) ? (int)$data['status'] : 1,
                ':updated_by' => $data['updated_by'] ?? null
            ]);
            
            if (!$result) {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'message' => 'Unable to update the sport'
                ];
            }
            
            // 2. Handle categories if provided
            if (isset($data['categories']) && is_array($data['categories'])) {
                // Get existing category IDs for this sport
                $existingCatStmt = $this->db->prepare("
                    SELECT id FROM table_kategori 
                    WHERE sukan_id = :sukan_id 
                    AND deleted_at IS NULL
                ");
                $existingCatStmt->execute([':sukan_id' => $id]);
                $existingCategoryIds = [];
                while ($row = $existingCatStmt->fetch(PDO::FETCH_ASSOC)) {
                    $existingCategoryIds[] = $row['id'];
                }
                
                // Track which categories are being kept
                $keptCategoryIds = [];
                
                // Update or create categories
                foreach ($data['categories'] as $category) {
                    if (!empty($category['nama_kategori'])) {
                        $categoryId = !empty($category['id']) ? (int)$category['id'] : null;
                        
                        if ($categoryId && in_array($categoryId, $existingCategoryIds)) {
                            // Update existing category
                            $updateCatStmt = $this->db->prepare("
                                UPDATE table_kategori
                                SET 
                                    nama_kategori = :nama_kategori,
                                    kod_kategori = :kod_kategori,
                                    keterangan = :keterangan,
                                    penilaian = :penilaian,
                                    status = :status,
                                    updated_by = :updated_by,
                                    updated_at = CURRENT_TIMESTAMP
                                WHERE id = :id
                                AND sukan_id = :sukan_id
                                AND deleted_at IS NULL
                            ");
                            
                            $updateCatStmt->execute([
                                ':id' => $categoryId,
                                ':sukan_id' => $id,
                                ':nama_kategori' => trim($category['nama_kategori']),
                                ':kod_kategori' => !empty($category['kod_kategori']) ? trim($category['kod_kategori']) : null,
                                ':keterangan' => !empty($category['keterangan']) ? trim($category['keterangan']) : null,
                                ':penilaian' => !empty($category['penilaian']) && in_array($category['penilaian'], ['berkumpulan', 'individu']) ? $category['penilaian'] : null,
                                ':status' => isset($category['status']) ? (int)$category['status'] : 1,
                                ':updated_by' => $data['updated_by'] ?? null
                            ]);
                            
                            $keptCategoryIds[] = $categoryId;
                        } else {
                            // Create new category
                            $createCatStmt = $this->db->prepare("
                                INSERT INTO table_kategori (
                                    sukan_id,
                                    nama_kategori,
                                    kod_kategori,
                                    keterangan,
                                    penilaian,
                                    status,
                                    created_by
                                ) VALUES (
                                    :sukan_id,
                                    :nama_kategori,
                                    :kod_kategori,
                                    :keterangan,
                                    :penilaian,
                                    :status,
                                    :created_by
                                )
                            ");
                            
                            $createCatStmt->execute([
                                ':sukan_id' => $id,
                                ':nama_kategori' => trim($category['nama_kategori']),
                                ':kod_kategori' => !empty($category['kod_kategori']) ? trim($category['kod_kategori']) : null,
                                ':keterangan' => !empty($category['keterangan']) ? trim($category['keterangan']) : null,
                                ':penilaian' => !empty($category['penilaian']) && in_array($category['penilaian'], ['berkumpulan', 'individu']) ? $category['penilaian'] : null,
                                ':status' => isset($category['status']) ? (int)$category['status'] : 1,
                                ':created_by' => $data['updated_by'] ?? null
                            ]);
                        }
                    }
                }
                
                // Soft delete categories that were removed
                $deletedCategoryIds = array_diff($existingCategoryIds, $keptCategoryIds);
                if (!empty($deletedCategoryIds)) {
                    $placeholders = implode(',', array_fill(0, count($deletedCategoryIds), '?'));
                    $deleteCatStmt = $this->db->prepare("
                        UPDATE table_kategori
                        SET 
                            deleted_at = CURRENT_TIMESTAMP,
                            updated_by = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id IN ($placeholders)
                        AND sukan_id = ?
                        AND deleted_at IS NULL
                    ");
                    
                    $params = array_merge(
                        [$data['updated_by'] ?? null],
                        $deletedCategoryIds,
                        [$id]
                    );
                    $deleteCatStmt->execute($params);
                }
            }
            
            // Commit transaction
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Sport and categories updated successfully'
            ];
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log('[SportModel::update] Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'System error: ' . $e->getMessage()
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log('[SportModel::update] Exception: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Soft delete sport
     * 
     * @param int $id Sport ID
     * @param int $deleted_by User ID who deleted
     * @return array Result with success status and message
     */
    public function delete($id, $deleted_by = null) {
        // Start transaction
        $this->db->beginTransaction();
        
        try {
            // Soft delete sport
            $stmt = $this->db->prepare("
                UPDATE table_sukan
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
                // Soft delete all categories for this sport
                $catStmt = $this->db->prepare("
                    UPDATE table_kategori
                    SET 
                        deleted_at = CURRENT_TIMESTAMP,
                        updated_by = :updated_by,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE sukan_id = :sukan_id
                    AND deleted_at IS NULL
                ");
                $catStmt->execute([
                    ':sukan_id' => $id,
                    ':updated_by' => $deleted_by
                ]);
                
                $this->db->commit();
                
                return [
                    'success' => true,
                    'message' => 'Sport and categories deleted successfully'
                ];
            }
            
            $this->db->rollBack();
            return [
                'success' => false,
                'message' => 'Sports not found'
            ];
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log('[SportModel::delete] Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'System error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get sport statistics
     * 
     * @return array Result with success status and statistics
     */
    public function getStatistics() {
        try {
            $stmt = $this->db->query("
                SELECT 
                    COUNT(DISTINCT s.id) AS total_sports,
                    SUM(CASE WHEN s.status = 1 THEN 1 ELSE 0 END) AS active_sports,
                    SUM(CASE WHEN s.status = 0 THEN 1 ELSE 0 END) AS inactive_sports,
                    COUNT(DISTINCT k.id) AS total_categories
                FROM table_sukan s
                LEFT JOIN table_kategori k ON s.id = k.sukan_id AND k.deleted_at IS NULL
                WHERE s.deleted_at IS NULL
            ");
            
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'data' => [
                    'total_sports' => (int)$stats['total_sports'],
                    'active_sports' => (int)$stats['active_sports'],
                    'inactive_sports' => (int)$stats['inactive_sports'],
                    'total_categories' => (int)$stats['total_categories']
                ]
            ];
        } catch (PDOException $e) {
            error_log('[SportModel::getStatistics] Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'System error: ' . $e->getMessage(),
                'data' => [
                    'total_sports' => 0,
                    'active_sports' => 0,
                    'inactive_sports' => 0,
                    'total_categories' => 0
                ]
            ];
        }
    }
}


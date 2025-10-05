<?php
// Delivery role start
class Delivery {
    private $conn;
    private $table_name = "delivery_orders";

    public $id;
    public $sale_id;
    public $delivery_person_id;
    public $status;
    public $notes;
    public $assigned_to;

    public function __construct($db) {
        $this->conn = $db;
    }

    function getAssignedDeliveries() {
        $query = "SELECT * FROM " . $this->table_name . " WHERE assigned_to = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->delivery_person_id);
        $stmt->execute();
        return $stmt;
    }

    // Delivery role start
    function assignDeliveryPerson() {
        $query = "UPDATE " . $this->table_name . " SET assigned_to = :assigned_to WHERE id = :delivery_order_id";
        $stmt = $this->conn->prepare($query);
        $this->id = htmlspecialchars(strip_tags($this->id));
        $this->assigned_to = htmlspecialchars(strip_tags($this->assigned_to));
        $stmt->bindParam(':delivery_order_id', $this->id);
        $stmt->bindParam(':assigned_to', $this->assigned_to);
        if ($stmt->execute()) { return true; }
        return false;
    }
    // Delivery role end

    function getDeliveryHistory() {
        $query = "SELECT * FROM " . $this->table_name . " WHERE assigned_to = ? AND status IN ('delivered','failed')";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->delivery_person_id);
        $stmt->execute();
        return $stmt;
    }

    function updateStatus() {
        $query = "UPDATE " . $this->table_name . " SET status = :status, updated_at = NOW() WHERE id = :id AND assigned_to = :assigned_to";
        $stmt = $this->conn->prepare($query);

        $this->status = htmlspecialchars(strip_tags($this->status));
        $this->id = htmlspecialchars(strip_tags($this->id));
        $this->assigned_to = htmlspecialchars(strip_tags($this->assigned_to));

        $stmt->bindParam(':status', $this->status);
        $stmt->bindParam(':id', $this->id);
        $stmt->bindParam(':assigned_to', $this->assigned_to);

        if ($stmt->execute()) {
            return true;
        }
        return false;
    }

    function updateRemarks() {
        $query = "UPDATE " . $this->table_name . " SET notes = :notes, updated_at = NOW() WHERE id = :id AND assigned_to = :assigned_to";
        $stmt = $this->conn->prepare($query);

        $this->notes = htmlspecialchars(strip_tags($this->notes));
        $this->id = htmlspecialchars(strip_tags($this->id));
        $this->assigned_to = htmlspecialchars(strip_tags($this->assigned_to));

        $stmt->bindParam(':notes', $this->notes);
        $stmt->bindParam(':id', $this->id);
        $stmt->bindParam(':assigned_to', $this->assigned_to);

        if ($stmt->execute()) { return true; }
        return false;
    }
}
// Delivery role end

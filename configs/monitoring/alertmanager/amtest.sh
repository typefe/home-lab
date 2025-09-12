#!/bin/bash

# Configuration
name=$RANDOM
instance="$name.example.net"
severity='warning'
summary='Testing Alertmanager'
service='my-service'
AM_URL='http://localhost:9093'

# Function to fire alert via Alertmanager API
fire_alert() {
    curl -s -XPOST "$AM_URL/api/v2/alerts" -H "Content-Type: application/json" -d "[
        {
            \"status\": \"firing\",
            \"labels\": {
                \"alertname\": \"$name\",
                \"service\": \"$service\",
                \"severity\": \"$severity\",
                \"instance\": \"$instance\"
            },
            \"annotations\": {
                \"summary\": \"$summary\",
                \"description\": \"This alert is firing for $instance\",
                \"description_resolved\": \"This alert has been resolved for $instance\"
            },
            \"generatorURL\": \"https://prometheus.local/<generating_expression>\"
        }
    ]"
    echo ""
    echo "Alert fired: $name"
}

# Function to resolve alert via amtool
resolve_alert() {
    amtool --alertmanager.url="$AM_URL" alert resolve \
        alertname="$name" \
        service="$service" \
        instance="$instance" \
        severity="$severity"
    echo "Alert resolved: $name"
}

# Main
fire_alert

read -p "Press enter to resolve alert"

resolve_alert
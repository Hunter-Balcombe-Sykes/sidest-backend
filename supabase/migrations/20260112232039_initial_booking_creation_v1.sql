CREATE SCHEMA IF NOT EXISTS "bookings";


ALTER SCHEMA "bookings" OWNER TO "postgres";

CREATE TABLE IF NOT EXISTS "bookings"."bookings" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    
);


bookings
    - id PRIMARY
    - time start
    - time end
    - service
    - duration
    - customer FOREIGN
        if customer not already created, create new
        else merge
    - professional FOREIGN
    - created_at

stores
    - id PRIMARY
    - name
    - address
    - email
    - phone
    - professional FOREIGN

employees
    - id PRIMARY
    - avaliabilities - hours working - days working e.g. Monday 9am to 5pm
    - unavaliabilites
    - holidays
    - email
    - phone
    - full name
    - store FOREIGN
    - professional FOREIGN
    - services FOREIGN
    - joined_date
    - created_at
    - deleted_at

Notifications
    - Booking Notificaiton email
        to customer
        to professional
        to employee
    - Booking Cancelation
        to customer
        to professional 
        to employee
    - Booking Reminder
        to customer
    - Employee Time Slot Reminder
        to employee

Function Avaliabilities

Function Hours Worked


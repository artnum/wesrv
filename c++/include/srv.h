#pragma once

#include <string>
#include <cstddef>
#include <thread>
#include <mutex>
#include <vector>
#include "msg.h"
#include "client.h"

namespace wesrv {
    using namespace std;
    
    class Srv {
        public:
            Srv(string udp_address, int udp_port, string tcp_address, int tcp_port, string key);
            void init();
            void writeLoop();
            ~Srv();
        private:
            const int MAX_PACKET_SIZE = 576;
            string key;
            string udp_address;
            int udp_port;
            string tcp_address;
            int tcp_port;
            int udp_socket;
            int tcp_socket;
            void loop();
            thread mainLoopThread;
            thread writeLoopThread;
            mutex mtxLoopControl;
            bool loopControl;
            mutex mtxWriteControl;
            vector<Msg*> writeControl;
            mutex mtxClientList;
            vector<Client*> clientList;
    };
}
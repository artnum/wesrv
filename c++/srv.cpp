#include "include/srv.h"
#include "include/msg.h"
#include "include/client.h"

#include <thread>
#include <mutex>
#include <chrono>
#include <iostream>
#include <string>
#include <stdexcept>
#include <netinet/in.h>
#include <sys/socket.h>
#include <arpa/inet.h>
#include <unistd.h>

namespace wesrv {
    using namespace std;

    Srv::Srv(string udp_address, int udp_port, string tcp_address, int tcp_port, string key) {
        this->key = key;
        this->udp_address = udp_address;
        this->udp_port = udp_port;
        this->tcp_address = tcp_address;
        this->tcp_port = tcp_port;
    }

    void Srv::init() {
        this->udp_socket = socket(AF_INET, SOCK_DGRAM, 0);
        if (this->udp_socket == -1) {
            throw runtime_error("udp_socket == -1");
        }
        sockaddr_in udp_addr;
        udp_addr.sin_family = AF_INET;
        udp_addr.sin_port = htons(this->udp_port);
        udp_addr.sin_addr.s_addr = inet_addr(this->udp_address.c_str());

        if (bind(this->udp_socket, (sockaddr *)&udp_addr, sizeof(udp_addr)) == -1) {
            throw runtime_error("bind == -1");
        }

        this->tcp_socket = socket(AF_INET, SOCK_STREAM, 0);
        if (this->tcp_socket == -1) {
            throw runtime_error("tcp_socket == -1");
        }
        sockaddr_in tcp_addr;
        tcp_addr.sin_family = AF_INET;
        tcp_addr.sin_port = htons(this->tcp_port);
        tcp_addr.sin_addr.s_addr = inet_addr(this->tcp_address.c_str());

        if (bind(this->tcp_socket, (sockaddr *)&tcp_addr, sizeof(tcp_addr)) == -1) {
            throw runtime_error("bind == -1");
        }
        if (listen(this->tcp_socket, 50) == -1) {
            throw runtime_error("listen == -1");
        }

        this->loopControl = true;

        thread t(&Srv::loop, this);
        this->mainLoopThread = move(t);
        thread t2(&Srv::writeLoop, this);
        this->writeLoopThread = move(t2);
        cout << "Server started" << endl;
        while(1) {}
    }

    void Srv::writeLoop() {
        bool loopControl = true;
        do {
            this->mtxLoopControl.lock();
            loopControl = this->loopControl;
            this->mtxLoopControl.unlock();
            if (!loopControl) {
                break;
            }
            vector <Msg*> writeControl;
            {
                const lock_guard<mutex> lock(this->mtxWriteControl);
                writeControl = this->writeControl;
            }
            this->mtxWriteControl.unlock();

            for (Msg* msg : writeControl) {
                cout << "[WRITE] " << msg->getContent() << endl;
                string packet = msg->packet();
                for (Client* client : this->clientList) {
                    send(client->getSocket(), packet.c_str(), packet.size(), 0);
                }
                delete msg;
            }

            this_thread::sleep_for(chrono::milliseconds(100));
        } while(loopControl);
    }

    void Srv::loop() {
        fd_set readfds;
        int selectResult;
        bool loopControl = true;
        timeval timeout;
        timeout.tv_sec = 0;
        timeout.tv_usec = 100000;

        FD_ZERO(&readfds);
        FD_SET(this->udp_socket, &readfds);
        FD_SET(this->tcp_socket, &readfds);

        do {
            {
                const lock_guard<mutex> lock(this->mtxLoopControl);
                loopControl = this->loopControl;
            }           
            if (!loopControl) {
                break;
            }
            cout << this->tcp_socket << endl;
            cout << this->udp_socket << endl;

            selectResult = select(FD_SETSIZE, &readfds, NULL, NULL, NULL);
            switch(selectResult) {
                case 0: break;
                case -1: loopControl = false; break;
                default:
                    if (FD_ISSET(this->udp_socket, &readfds)) {
                        cout << "UDP" << endl;
                        unsigned char buffer[this->MAX_PACKET_SIZE];
                        sockaddr_in client;
                        socklen_t client_len = sizeof(client);
                        int len = recvfrom(this->udp_socket, buffer, sizeof(buffer), 0, (sockaddr *)&client, &client_len);
                        if (len > 0) {
                            Msg* msg = new Msg(this->key, buffer, len);
                            cout << "[MESSAGE] " << msg->getContent() << endl;
                            try {
                                {
                                    const lock_guard<mutex> lock(this->mtxWriteControl);
                                    this->writeControl.push_back(msg);
                                }
                            } catch (runtime_error &e) {
                                cerr << "[ERR] " << e.what() << endl;
                            }
                        }
                    }
                    if (FD_ISSET(this->tcp_socket, &readfds)) {
                        cout << "New connection" << endl;
                        sockaddr_in client;
                        socklen_t client_len = sizeof(client);
                        int client_socket = accept(this->tcp_socket, (sockaddr *)&client, &client_len);
                        if (client_socket != -1) {
                            Client* c = new Client(client_socket, inet_ntoa(client.sin_addr), ntohs(client.sin_port));
                            cout << "[CLIENT] ";
                            c->dumpClient();
                            try {
                                {
                                    const lock_guard<mutex> lock(this->mtxClientList);
                                    this->clientList.push_back(c);
                                }
                                FD_SET(client_socket, &readfds);
                            } catch (runtime_error &e) {
                                cerr << "[ERR] " << e.what() << endl;
                            }
        
                        }
                    }

                    for (Client* client : this->clientList) {
                        if (FD_ISSET(client->getSocket(), &readfds)) {
                            unsigned char buffer[this->MAX_PACKET_SIZE];
                            int len = recv(client->getSocket(), buffer, sizeof(buffer), 0);
                            if (len > 0) {
                                try {
                                    {
                                        const lock_guard<mutex> lock(this->mtxWriteControl);
                                        this->writeControl.push_back(new Msg(this->key, buffer, len));
                                    }
                                } catch (runtime_error &e) {
                                    cerr << "[ERR]" << e.what() << endl;
                                }
                            }
                        }
                    }
            }
        } while(loopControl);
        FD_ZERO(&readfds);
    }

    Srv::~Srv() {
        {
            const lock_guard<mutex> lock(this->mtxLoopControl);
            this->loopControl = false;
        }

        this->mainLoopThread.join();
        this->writeLoopThread.join();

        {
            const lock_guard<mutex> lock(this->mtxWriteControl);
            for (Msg* msg : this->writeControl) {
                delete msg;
            }
        }
        
        {
            const lock_guard<mutex> lock(this->mtxClientList);
            for (Client* client : this->clientList) {
                delete client;
            }
        }

        close(this->udp_socket);
        close(this->tcp_socket);
    }
}